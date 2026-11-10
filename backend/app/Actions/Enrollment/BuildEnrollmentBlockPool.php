<?php

namespace App\Actions\Enrollment;

use App\Actions\Academic\ResolveCreditedSubjectIds;
use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\PrerequisiteEvaluator;
use App\Domain\Academic\PrerequisiteVerdict;
use App\Domain\Curriculum\SemesterCoverage;
use App\Domain\Enrollment\EnrollmentAudience;
use App\Domain\Enrollment\EnrollmentBlock;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\SchedulePreferenceScorer;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\StudentSchedulePreference;

/**
 * The blocks a regular student may choose from: every section their
 * college's Program Chair generated for the student's own year level and
 * curriculum, grouped by block code (DFD 2.2 sibling for block-based
 * enrollment; FR-ENR-001–003, 011).
 *
 * Irregular students always enrol per subject via `BuildEligibleSubjectPool`
 * instead — they return an empty pool here, not an error, so the caller can
 * render "you don't use this view" rather than a broken empty state.
 */
final readonly class BuildEnrollmentBlockPool
{
    public function __construct(
        private PrerequisiteEvaluator $evaluator,
        private BuildEnrollmentAccessContext $accessContext,
    ) {}

    /**
     * `$ignoreEnrollmentWindow` is for a freshman paying at the Cashier (ADR 0043): the Cashier
     * is the gate there, so a closed audience window must not make every block look
     * `window_closed`. Every other reason (seats, schedule, prerequisites) still applies.
     *
     * @return list<EnrollmentBlock>
     */
    public function execute(StudentProfile $student, AcademicTerm $term, bool $ignoreEnrollmentWindow = false): array
    {
        $context = $this->accessContext->execute($term, $student);

        if ($context->viewerAudience === EnrollmentAudience::Irregular) {
            return [];
        }

        $sections = Section::query()
            ->where('academic_term_id', $term->id)
            ->where('status', SectionStatus::Published)
            ->where('is_block_exclusive', true)
            ->whereHas('sectionPlan', function ($query) use ($student): void {
                $query->where('year_level', $student->year_level)
                    ->where('curriculum_id', $student->curriculum_id);
            })
            ->with(['subject', 'professor', 'sectionPlan'])
            ->orderBy('section_code')
            ->get();

        $alreadyEnrolled = $this->hasActiveEnrollmentThisTerm($student->id, $term->id);

        // Loaded once for the whole pool, not per block — one row per
        // student, and every block is scored against the same preference.
        $preference = StudentSchedulePreference::query()->where('student_id', $student->id)->first();

        // Subjects the student is credited for (a transferee credit, including one the Program Chair passed on,
        // or a curriculum migration): a credited prerequisite is met, as `ClassifyEnrollmentStanding` counts it.
        $credited = (new ResolveCreditedSubjectIds)->forStudents([$student->id], $student->curriculum_id, includeEndorsed: true)[$student->id] ?? [];

        $blocks = $sections
            ->groupBy('section_code')
            ->map(fn ($blockSections, string $blockCode): EnrollmentBlock => $this->buildBlock(
                $student,
                $blockCode,
                array_values($blockSections->all()),
                $context->viewerWindowIsOpen || $ignoreEnrollmentWindow,
                $alreadyEnrolled,
                $preference,
                $credited,
            ));

        return array_values($blocks->all());
    }

    /**
     * @param  list<Section>  $sections
     * @param  array<int, true>  $credited  subject id => the student is credited for it
     */
    private function buildBlock(
        StudentProfile $student,
        string $blockCode,
        array $sections,
        bool $windowIsOpen,
        bool $alreadyEnrolled,
        ?StudentSchedulePreference $preference,
        array $credited,
    ): EnrollmentBlock {
        /** @var list<array{code: string, message: string}> $reasons */
        $reasons = [];

        if ($alreadyEnrolled) {
            $reasons[] = ['code' => 'already_enrolled', 'message' => 'You already have an active enrollment for this term.'];
        }

        if (! $windowIsOpen) {
            $reasons[] = ['code' => 'window_closed', 'message' => 'Enrollment for your year level is not currently open.'];
        }

        // A missing professor or room never withholds a section —
        // assignment and room booking can both trail behind publication (the
        // Program Chair fills these in after release), and a student
        // choosing among sections shouldn't be blocked waiting for that to
        // happen. Day and time are the actual commitment a student makes by
        // choosing a section, so those still have to be set.
        $incomplete = array_filter(
            $sections,
            fn (Section $section): bool => $section->schedule_days === null
                || $section->starts_at_time === null
                || $section->ends_at_time === null,
        );
        if ($incomplete !== []) {
            $reasons[] = ['code' => 'incomplete_schedule', 'message' => 'This section is not fully scheduled yet — check back once every subject has a day and time.'];
        }

        $placements = CurriculumSubject::query()
            ->where('curriculum_id', $student->curriculum_id)
            ->whereIn('subject_id', array_map(fn (Section $section): int => $section->subject_id, $sections))
            ->with('prerequisites.prerequisiteSubject')
            ->get()
            ->keyBy('subject_id');

        foreach ($sections as $section) {
            // Prerequisites are scoped per curriculum placement (Subject
            // itself carries no prerequisite relation) — a subject absent
            // from this curriculum's placements simply has none to check.
            $placement = $placements->get($section->subject_id);

            $ownGrade = $this->latestLockedGrade($student->id, $section->subject_id);
            $alreadyPassed = $this->verdictFor($ownGrade, (string) config('enrollment.grading.passing_grade'))->isSatisfied();
            // A subject offered either semester (SemesterCoverage::
            // coversBoth() — the composite '1st|2nd' placement) is designed
            // to be taken in whichever term suits the student; already
            // having passed it is the normal, expected outcome of that
            // flexibility, not grounds to withhold the block.
            $offeredEitherSemester = $placement !== null && SemesterCoverage::coversBoth($placement->semester);
            if ($alreadyPassed && ! $offeredEitherSemester) {
                $reasons[] = ['code' => 'partially_completed', 'message' => "{$section->subject->code} has already been completed with a passing grade. See the Registrar about enrolling as an irregular student."];
            }

            $prerequisites = $placement !== null ? $placement->prerequisites : [];
            foreach ($prerequisites as $edge) {
                if (isset($credited[$edge->prerequisite_subject_id])) {
                    continue;
                }

                $prerequisiteGrade = $this->latestLockedGrade($student->id, $edge->prerequisite_subject_id);
                $verdict = $this->verdictFor($prerequisiteGrade, $edge->minimum_grade);

                if ($verdict->status->value === 'not_satisfied') {
                    $reasons[] = ['code' => 'prerequisite', 'message' => sprintf('%s requires %s: %s', $section->subject->code, $edge->prerequisiteSubject->code, $verdict->reason)];
                }
            }
        }

        // A reduce, not min(): `min([])` throws at runtime, and PHPStan
        // cannot prove a `list<Section>` built from `groupBy()` is
        // non-empty even though every group always has at least one member.
        $seatsRemaining = array_reduce(
            $sections,
            fn (?int $carry, Section $section): int => $carry === null
                ? $section->remainingSeats()
                : min($carry, $section->remainingSeats()),
            null,
        ) ?? 0;
        if ($seatsRemaining <= 0) {
            $reasons[] = ['code' => 'block_full', 'message' => 'At least one subject in this section has no open seats.'];
        }

        $totalUnits = array_sum(array_map(fn (Section $section): float => (float) $section->subject->units, $sections));
        $firstSection = $sections[0];
        $sectionPlan = $firstSection->sectionPlan;
        $sectionPlanId = $firstSection->section_plan_id;
        $yearLevel = $sectionPlan !== null ? $sectionPlan->year_level : $student->year_level;
        $curriculumId = $sectionPlan !== null ? $sectionPlan->curriculum_id : $student->curriculum_id;

        $scoring = SchedulePreferenceScorer::score($preference, $this->scorableSections($sections));

        return new EnrollmentBlock(
            blockCode: $blockCode,
            yearLevel: $yearLevel,
            curriculumId: $curriculumId,
            sectionPlanId: $sectionPlanId,
            sections: $sections,
            totalUnits: $totalUnits,
            seatsRemaining: max(0, $seatsRemaining),
            isSelectable: $reasons === [],
            reasons: $reasons,
            preferenceScore: $scoring['score'],
            preferenceReasons: $scoring['reasons'],
        );
    }

    /**
     * Adapts `list<Section>` to `SchedulePreferenceScorer::score()`'s plain
     * `SectionConflictDetector`-shaped rows — additive only, this never
     * changes what `$sections` itself contains.
     *
     * @param  list<Section>  $sections
     * @return list<array{schedule_days: ?string, starts_at_time: ?string, modality: ?string}>
     */
    private function scorableSections(array $sections): array
    {
        return array_map(
            fn (Section $section): array => [
                'schedule_days' => $section->schedule_days,
                'starts_at_time' => $section->starts_at_time,
                'modality' => $section->modality?->value,
            ],
            $sections,
        );
    }

    private function latestLockedGrade(int $studentId, int $subjectId): ?AcademicGrade
    {
        return AcademicGrade::query()
            ->where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->where('status', GradeStatus::Locked)
            ->orderByDesc('academic_term_id')
            ->orderByDesc('id')
            ->first(['mark', 'final_grade']);
    }

    /**
     * `C` (Complete) satisfies a completion-only subject's own-completion
     * check and every prerequisite edge that requires it, without ever
     * reaching `PrerequisiteEvaluator` — see the identical helper in
     * `BuildEligibleSubjectPool` for the full rationale.
     */
    private function verdictFor(?AcademicGrade $grade, string $required): PrerequisiteVerdict
    {
        // See the identical comment in BuildEligibleSubjectPool::verdictFor()
        // for why this is split into a local variable rather than chained.
        $mark = $grade?->mark;

        if ($mark?->isCompletion() === true) {
            return PrerequisiteVerdict::satisfied('Recorded mark C (Complete) satisfies this completion-only requirement.');
        }

        // Explicit null checks: see BuildEligibleSubjectPool::verdictFor()
        // for why neither `?->` nor plain `->` satisfies PHPStan here.
        $markValue = $mark !== null ? $mark->value : null;
        $finalGrade = $grade !== null ? $grade->final_grade : null;
        $rawGrade = $markValue ?? $finalGrade;

        return $this->evaluator->evaluate($rawGrade, $required);
    }

    private function hasActiveEnrollmentThisTerm(int $studentId, int $academicTermId): bool
    {
        return Enrollment::query()
            ->where('student_id', $studentId)
            ->where('academic_term_id', $academicTermId)
            ->whereNotIn('status', EnrollmentStatus::terminalValues())
            ->exists();
    }
}

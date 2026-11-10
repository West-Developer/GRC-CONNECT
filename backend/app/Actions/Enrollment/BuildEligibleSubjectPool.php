<?php

namespace App\Actions\Enrollment;

use App\Actions\Academic\EvaluateCreditMappingStatus;
use App\Actions\Academic\ResolveCreditedSubjectIds;
use App\Domain\Academic\CreditMappingStatusResult;
use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\PrerequisiteEvaluator;
use App\Domain\Academic\PrerequisiteVerdict;
use App\Domain\Curriculum\SemesterCoverage;
use App\Domain\Curriculum\SemesterSlot;
use App\Domain\Enrollment\BlockSectionAccessPolicy;
use App\Domain\Enrollment\EligibleSubjectEntry;
use App\Domain\Enrollment\EnrollmentAccessContext;
use App\Domain\Enrollment\EnrollmentAudience;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\SchedulePreferenceScorer;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\CurriculumSubject;
use App\Models\EnrollmentSubject;
use App\Models\EnrollmentSubjectWaiver;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\StudentSchedulePreference;
use App\Models\Subject;

/**
 * DFD 2.2 "Validate Capacities and Prerequisites": cross-references
 * STUDENT RECORDS against CURRICULUM AND SCHEDULING to produce the Eligible
 * Subject Pool (FR-ENR-001–003, 005, 011). Every entry is explainable
 * (FR-ENR-005) — a subject is never silently omitted, it is always returned
 * with the reason it is or is not currently selectable.
 *
 * FR-ENR-003's "conflicting" section exclusion is deliberately NOT applied
 * here: the acceptance criterion is "two conflicting sections cannot be
 * SUBMITTED together," and there is no partial/draft selection state between
 * viewing the pool and Phase 6 Task 5's atomic submission for two sections to
 * conflict against yet. SectionConflictDetector is reused there instead,
 * comparing the full submitted set.
 */
final readonly class BuildEligibleSubjectPool
{
    public function __construct(
        private PrerequisiteEvaluator $evaluator,
        private BuildEnrollmentAccessContext $accessContext,
    ) {}

    /**
     * @return list<EligibleSubjectEntry>
     */
    public function execute(StudentProfile $student, AcademicTerm $term, ?int $excludingEnrollmentId = null): array
    {
        $placements = CurriculumSubject::query()
            ->where('curriculum_id', $student->curriculum_id)
            ->with(['subject', 'prerequisites.prerequisiteSubject'])
            ->orderBy('year_level')
            ->orderBy('semester')
            ->get();

        // Irregular students pick subjects one at a time rather than taking
        // a published block, so unlike a regular student — who is already
        // implicitly bounded to their own year's block sections by
        // BlockSectionAccessPolicy below — an irregular student would
        // otherwise see the entire 4-year curriculum at once, most of it
        // irrelevant. The pool is bounded to two windows: everything up to
        // and including the term being enrolled into (their current
        // semester plus any not-yet-taken backlog from an earlier one), and
        // — separately — the same semester slot one year ahead, so a
        // student who is caught up can still get ahead. Nothing two or more
        // years ahead is ever offered; the frontend (EligibleSubjectTable)
        // is what keeps the "one year ahead" window out of the default
        // selection and behind its "Add subject" picker, using the same
        // ordinal math client-side (curriculum-ordinal.ts's
        // isAdvanceSubject). A placement offered either semester (the
        // composite '1st|2nd' string) matches on whichever of its two
        // ordinals falls in either window, per
        // App\Domain\Curriculum\SemesterCoverage's own contract.
        //
        // Resolved once for the whole pool: which audience windows are open
        // is a property of the term, not of any single placement. It also
        // carries the student's LIVE audience (ADR 0028) - the stored
        // `enrollment_category` can still be stale on the very request that
        // self-heals it (e.g. right after promotion), and would otherwise let
        // a newly Irregular student see the whole curriculum for one request.
        $context = $this->accessContext->execute($term, $student);

        if ($context->viewerAudience === EnrollmentAudience::Irregular) {
            $currentOrdinal = (SemesterSlot::tryFrom($term->semester) ?? SemesterSlot::First)->ordinal($student->year_level);

            $placements = $placements->filter(function (CurriculumSubject $placement) use ($currentOrdinal): bool {
                foreach (SemesterCoverage::parse($placement->semester) as $slot) {
                    $ordinal = $slot->ordinal($placement->year_level);
                    if ($ordinal <= $currentOrdinal || $ordinal === $currentOrdinal + 2) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        // Loaded once for the whole pool, not per placement — one row per
        // student, and every entry is scored against the same preference.
        $preference = StudentSchedulePreference::query()->where('student_id', $student->id)->first();
        // Subjects the student already has without a GRC grade, each with where
        // the credit came from: an old-curriculum migration or an approved
        // credit for a subject taken at another school (ADR 0026).
        $credits = new ResolveCreditedSubjectIds;
        $creditedSubjectIds = [];
        // A credit the Program Chair passed to the Registrar Head is credited for enrolment already
        // (owner, 2026-10-15); the approved ones below overwrite it.
        foreach (array_keys($credits->fromEndorsedTransfereeCredits([$student->id])[$student->id] ?? []) as $subjectId) {
            $creditedSubjectIds[$subjectId] = 'previous_school_pending';
        }
        foreach (array_keys($credits->fromApprovedTransfereeCredits([$student->id])[$student->id] ?? []) as $subjectId) {
            $creditedSubjectIds[$subjectId] = 'previous_school';
        }
        foreach (array_keys($credits->fromCurriculumMigrations([$student->id], $student->curriculum_id)[$student->id] ?? []) as $subjectId) {
            $creditedSubjectIds[$subjectId] = 'curriculum';
        }

        // Prerequisite waivers the Registrar Head granted for this student and
        // term (ADR 0031): they lift the `prerequisite` exclusion only.
        $waivedSubjectIds = array_fill_keys(
            EnrollmentSubjectWaiver::query()
                ->active()
                ->where('student_id', $student->id)
                ->where('academic_term_id', $term->id)
                ->pluck('subject_id')
                ->all(),
            true,
        );

        // Transferee and returnee students must complete credit mapping / curriculum
        // migration before enrollment can proceed.
        $creditMappingStatus = (new EvaluateCreditMappingStatus)->execute($student);

        return array_values(array_map(
            fn (CurriculumSubject $placement): EligibleSubjectEntry => $this->evaluatePlacement($student, $term, $placement, $context, $preference, $creditedSubjectIds, $waivedSubjectIds, $creditMappingStatus, $excludingEnrollmentId),
            $placements->all(),
        ));
    }

    /**
     * @param  array<int, string>  $creditedSubjectIds  subject id => where the credit came from ('curriculum', 'previous_school', or 'previous_school_pending' while the Registrar Head has not approved it)
     * @param  array<int, true>  $waivedSubjectIds  subject id => a Registrar Head prerequisite waiver is active for this student and term
     */
    private function evaluatePlacement(
        StudentProfile $student,
        AcademicTerm $term,
        CurriculumSubject $placement,
        EnrollmentAccessContext $context,
        ?StudentSchedulePreference $preference,
        array $creditedSubjectIds,
        array $waivedSubjectIds = [],
        ?CreditMappingStatusResult $creditMappingStatus = null,
        ?int $excludingEnrollmentId = null,
    ): EligibleSubjectEntry {
        /** @var list<array{code: string, message: string}> $reasons */
        $reasons = [];
        $excluded = false;

        if ($creditMappingStatus !== null && ! $creditMappingStatus->isCompleted) {
            $reasons[] = [
                'code' => 'credit_mapping_pending',
                'message' => $creditMappingStatus->reason ?? 'Credit mapping must be completed and approved before enrolling.',
            ];
            $excluded = true;
        }

        $siblingSubjectIds = $this->siblingSubjectIds($placement->subject);

        $ownGrade = $this->latestLockedGrade($student->id, $siblingSubjectIds);
        if (isset($creditedSubjectIds[$placement->subject_id])) {
            $reasons[] = ['code' => 'completed', 'message' => match ($creditedSubjectIds[$placement->subject_id]) {
                'previous_school' => 'This subject was credited from the student\'s previous school.',
                'previous_school_pending' => 'This subject is credited from the student\'s previous school; the Registrar Head has yet to approve the credit.',
                default => 'This subject was credited from the student\'s prior curriculum.',
            }];
            $excluded = true;
        } elseif ($this->verdictFor($ownGrade, (string) config('enrollment.grading.passing_grade'))->isSatisfied()) {
            $reasons[] = ['code' => 'completed', 'message' => 'This subject has already been completed with a passing grade.'];
            $excluded = true;
        }

        if ($this->isAlreadySelectedThisTerm($student->id, $term->id, $siblingSubjectIds, $excludingEnrollmentId)) {
            $reasons[] = ['code' => 'already_selected', 'message' => 'This subject is already part of your current enrollment for this term.'];
            $excluded = true;
        }

        $waiverNoted = false;
        foreach ($placement->prerequisites as $edge) {
            if (isset($creditedSubjectIds[$edge->prerequisite_subject_id])) {
                continue;
            }
            $prerequisiteGrade = $this->latestLockedGrade(
                $student->id,
                $this->siblingSubjectIds($edge->prerequisiteSubject),
            );
            $verdict = $this->verdictFor($prerequisiteGrade, $edge->minimum_grade);

            if ($verdict->status->value === 'not_satisfied' && $this->isWaived($siblingSubjectIds, $waivedSubjectIds)) {
                if (! $waiverNoted) {
                    $reasons[] = [
                        'code' => 'prerequisite_waived',
                        'message' => 'The Registrar Head waived an unmet prerequisite for this subject this term.',
                    ];
                    $waiverNoted = true;
                }
            } elseif ($verdict->status->value === 'not_satisfied') {
                $reasons[] = [
                    'code' => 'prerequisite',
                    'message' => sprintf('%s: %s', $edge->prerequisiteSubject->code, $verdict->reason),
                ];
                $excluded = true;
            } elseif ($verdict->status->value === 'needs_verification') {
                $reasons[] = [
                    'code' => 'prerequisite_advisory',
                    'message' => sprintf('%s: %s', $edge->prerequisiteSubject->code, $verdict->reason),
                ];
            }
        }

        $availableSections = [];

        if (! $excluded) {
            $sectionsThisTerm = Section::query()
                ->where('academic_term_id', $term->id)
                ->whereIn('subject_id', $siblingSubjectIds)
                ->with(['sectionPlan', 'subject', 'professor'])
                ->get();

            $openSections = $sectionsThisTerm
                ->filter(fn (Section $section): bool => $section->status === SectionStatus::Published && $section->remainingSeats() > 0)
                ->values();

            if ($openSections->isEmpty()) {
                $reasons[] = ['code' => 'no_sections_available', 'message' => 'No published sections with open seats are offered for this subject in the selected term.'];
                $excluded = true;
            } else {
                $unrestricted = $openSections->filter(
                    fn (Section $section): bool => BlockSectionAccessPolicy::allows(
                        $section->is_block_exclusive,
                        $this->blockYearLevel($section),
                        $context,
                    ),
                )->values();

                if ($unrestricted->isEmpty()) {
                    // $openSections was already proven non-empty above.
                    $representativeSection = $openSections->first();
                    $withheldCode = BlockSectionAccessPolicy::reasonFor(
                        $representativeSection->is_block_exclusive,
                        $this->blockYearLevel($representativeSection),
                        $context,
                    ) ?? 'block_restricted';

                    $reasons[] = $withheldCode === 'block_other_year'
                        ? ['code' => 'block_other_year', 'message' => 'The only open sections for this subject belong to another year level\'s block.']
                        : ['code' => 'block_restricted', 'message' => 'The only open sections for this subject are block sections, which open to irregular students when the irregular enrollment window starts.'];
                    $excluded = true;
                } else {
                    $availableSections = array_values($unrestricted->all());
                }
            }
        }

        if (! $excluded && $reasons === []) {
            $reasons[] = ['code' => 'eligible', 'message' => 'All curriculum and prerequisite requirements are met.'];
        }

        $scoring = SchedulePreferenceScorer::score($preference, $this->scorableSections($availableSections));

        return new EligibleSubjectEntry(
            subject: $placement->subject,
            placement: $placement,
            isEligible: ! $excluded,
            reasons: $reasons,
            availableSections: $availableSections,
            preferenceScore: $scoring['score'],
            preferenceReasons: $scoring['reasons'],
        );
    }

    /**
     * @param  list<int>  $siblingSubjectIds
     * @param  array<int, true>  $waivedSubjectIds
     */
    private function isWaived(array $siblingSubjectIds, array $waivedSubjectIds): bool
    {
        foreach ($siblingSubjectIds as $subjectId) {
            if (isset($waivedSubjectIds[$subjectId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adapts `list<Section>` to `SchedulePreferenceScorer::score()`'s plain
     * `SectionConflictDetector`-shaped rows — additive only, this never
     * changes what `$availableSections` itself contains.
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

    /**
     * Every `subjects` row sharing this subject's code and units is the same
     * course offered under a different college -- general-education subjects
     * (RIZAL, NSTP, PATHFIT, ...) are duplicated one row per college, while a
     * major subject has no such sibling. The whole sibling set is what a
     * student may be offered sections from, what counts as already selected,
     * and what a locked grade may have been filed against — so completion and
     * every prerequisite edge are evaluated over the set, not one row.
     *
     * `array_values()` is not decorative: `Collection::all()` is typed
     * `array<int, int>`, which static analysis cannot narrow to a `list<int>`
     * on its own.
     *
     * @return list<int>
     */
    private function siblingSubjectIds(Subject $subject): array
    {
        return array_values(
            Subject::query()
                ->where('code', $subject->code)
                ->where('units', $subject->units)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );
    }

    /**
     * @param  list<int>  $subjectIds
     */
    private function latestLockedGrade(int $studentId, array $subjectIds): ?AcademicGrade
    {
        return AcademicGrade::query()
            ->where('student_id', $studentId)
            ->whereIn('subject_id', $subjectIds)
            ->where('status', GradeStatus::Locked)
            ->orderByDesc('academic_term_id')
            ->orderByDesc('id')
            ->first(['mark', 'final_grade']);
    }

    /**
     * `C` (Complete) satisfies a completion-only subject's own-completion
     * check and every prerequisite edge that requires it, without ever
     * reaching `PrerequisiteEvaluator` — that evaluator only compares
     * numeric grades and treats every non-numeric value (including `C`) as
     * a special mark, which would make `LEAD1`'s `C` permanently fail to
     * satisfy `LEAD2`'s prerequisite. This short-circuit is the fix; the
     * evaluator itself is intentionally left untouched.
     *
     * Falls back to `mark->value` (e.g. `'NC'`, `'INC'`) or, for legacy
     * rows locked before `mark` existed, the raw `final_grade` string — so
     * `PrerequisiteEvaluator`'s existing special-marks/numeric handling
     * still runs unchanged for every case except Complete.
     */
    private function verdictFor(?AcademicGrade $grade, string $required): PrerequisiteVerdict
    {
        // Read into a local first: chaining a second `?->` off the result of
        // the first (`$grade?->mark?->x`) only protects against `$grade`
        // itself being null. If `$grade` is non-null but `mark` is null, a
        // further method call needs its own `?->` — a plain `->method()` on
        // null is a fatal error, unlike plain property access.
        $mark = $grade?->mark;

        if ($mark?->isCompletion() === true) {
            return PrerequisiteVerdict::satisfied('Recorded mark C (Complete) satisfies this completion-only requirement.');
        }

        // Explicit null checks, not `?->`/plain `->`: PHP itself tolerates
        // `$mark->value` here even when `$mark` is null (a property-access
        // chain on the left of `??` degrades silently to null, with no
        // nullsafe operator needed) — but PHPStan's static analysis can't
        // see that runtime allowance and flags it as a possible-null
        // dereference regardless. Explicit checks satisfy both.
        $markValue = $mark !== null ? $mark->value : null;
        $finalGrade = $grade !== null ? $grade->final_grade : null;
        $rawGrade = $markValue ?? $finalGrade;

        return $this->evaluator->evaluate($rawGrade, $required);
    }

    /**
     * @param  list<int>  $subjectIds
     */
    private function isAlreadySelectedThisTerm(int $studentId, int $academicTermId, array $subjectIds, ?int $excludingEnrollmentId = null): bool
    {
        return EnrollmentSubject::query()
            ->whereHas(
                'enrollment',
                fn ($query) => $query
                    ->where('student_id', $studentId)
                    ->where('academic_term_id', $academicTermId)
                    ->whereNotIn('status', EnrollmentStatus::terminalValues()),
            )
            ->whereHas('section', fn ($query) => $query->whereIn('subject_id', $subjectIds))
            ->when($excludingEnrollmentId !== null, fn ($query) => $query->where('enrollment_id', '!=', $excludingEnrollmentId))
            ->where('status', '!=', EnrollmentSubjectStatus::Dropped->value)
            ->exists();
    }

    /**
     * The year level a block section belongs to.
     *
     * `section_plan_id` is authoritative — it is the row the Program Chair
     * actually planned against. The section-code suffix is only a fallback
     * for legacy rows generated before section plans existed (`IT201` → 2),
     * and null when neither is available, which the access policy treats as
     * "do not withhold".
     */
    private function blockYearLevel(?Section $section): ?int
    {
        if ($section === null) {
            return null;
        }

        if ($section->sectionPlan !== null) {
            return $section->sectionPlan->year_level;
        }

        return preg_match('/(\d)\d{2}$/u', $section->section_code, $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}

<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\PrerequisiteEvaluator;
use App\Domain\Curriculum\SemesterCoverage;
use App\Domain\Curriculum\SemesterSlot;
use App\Domain\Enrollment\ClassificationVerdict;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\CurriculumSubject;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\TransfereeCredit;
use Illuminate\Database\Eloquent\Collection;

/**
 * Term-scoped Regular/Irregular standing (spec
 * docs/superpowers/specs/2026-08-19-term-scoped-enrollment-standing-design.md),
 * superseding the 2026-08-04 cumulative-lifetime rule that used to live in
 * the now-deleted `App\Domain\Enrollment\EnrollmentCategoryClassifier`. A
 * student is Regular for a term when they can take exactly the standard
 * block subject set the Program Chair published for their year level —
 * nothing needs adding (an offered backlog subject outside the block) and
 * nothing needs removing (a block subject the student is already credited for,
 * or one blocked by an unmet prerequisite). A block subject passed with a grade
 * does not make a student Irregular; a credited one does (owner, 2026-10-15),
 * because a credit is not a seat to take again.
 *
 * `classifyMany()` is the batch entry point — every student passed in MUST
 * share the same `(curriculum_id, year_level)`; the caller
 * (`ReclassifyStudentEnrollmentCategory`) groups students by that pair
 * before calling, so the standard-set/placement/backlog queries run once
 * per group, not once per student. `classify()` is a thin single-student
 * wrapper for callers with exactly one student in hand.
 *
 * `null` means undetermined — never coerce this to Irregular. Two cases: no
 * block published yet for that year level this term, and a student with no
 * record in the system at all (no locked result, credit or earlier-term
 * enrollment), whom Admission has just created and nothing can be derived
 * for (owner, 2026-10-13). Forcing Irregular here would
 * eagerly flip every student in that year level the instant they open
 * their enrollment page, purely because setup isn't finished yet. The one
 * exception is a failed back subject (ADR 0028): a required subject from an
 * earlier point in the curriculum, taken and not passed in a prior term,
 * makes the student Irregular whether or not any section or block exists
 * yet, because that fact does not depend on this term's setup.
 */
final readonly class ClassifyEnrollmentStanding
{
    public function __construct(private PrerequisiteEvaluator $evaluator) {}

    public function classify(StudentProfile $student, AcademicTerm $term): ?ClassificationVerdict
    {
        return $this->classifyMany(new Collection([$student]), $term)[$student->id];
    }

    /**
     * @param  Collection<int, StudentProfile>  $students  Every student must share the same curriculum_id and year_level.
     * @return array<int, ?ClassificationVerdict>
     */
    public function classifyMany(Collection $students, AcademicTerm $term): array
    {
        if ($students->isEmpty()) {
            return [];
        }

        $first = $students->first();
        $curriculumId = $first->curriculum_id;
        $yearLevel = $first->year_level;
        // array_values(), not Collection::values(): PHPStan/Larastan's
        // Collection stubs type ->values() as merely int-keyed, not
        // provably sequential from 0 -- array_values() is a plain PHP
        // function PHPStan always recognizes as producing a list<T>.
        $studentIds = array_values($students->map(fn (StudentProfile $s): int => $s->id)->all());

        $standardSubjectIds = $this->standardBlockSubjectIds($term, $curriculumId, $yearLevel);

        // No block published yet for this year level: standing is only
        // undetermined (null) when nothing else already settles it. A failed
        // back subject (below) does not depend on any section being published.
        $blockPublished = $standardSubjectIds !== [];

        $placements = CurriculumSubject::query()
            ->where('curriculum_id', $curriculumId)
            ->with(['prerequisites', 'subject:id,code'])
            ->get()
            ->keyBy('subject_id');

        $backlogSubjectIds = array_values(array_map(
            static fn (int|string $id): int => (int) $id,
            $placements->keys()->diff($standardSubjectIds)->all(),
        ));
        $openBacklogSubjectIds = $blockPublished
            ? $this->openNonBlockSectionSubjectIds($term, $backlogSubjectIds)
            : [];

        // A backlog subject only counts if the student should already have
        // reached it by now — a subject placed at a LATER point in the
        // curriculum than the student's own standard block this term is
        // still ahead of them, not something they need to add. Bounding by
        // ordinal keeps a non-block-exclusive section for a future
        // year/semester from ever being misread as "needs adding."
        $currentOrdinal = (SemesterSlot::tryFrom($term->semester) ?? SemesterSlot::First)->ordinal($yearLevel);

        $marksByStudent = $this->latestLockedMarksByStudent($studentIds);
        $creditedByStudent = $this->creditedSubjectIdsByStudent($studentIds, $curriculumId);
        $withoutRecord = $this->studentIdsWithoutRecord($studentIds, $term, $marksByStudent, $creditedByStudent);

        $verdicts = [];

        foreach ($students as $student) {
            // A student with no record in the system cannot be judged: every earlier subject would
            // look "not passed" and every prerequisite "not met", so a student Admission has just
            // created would turn Irregular on their own (owner, 2026-10-13). Their stored category,
            // the one Admission chose, stands until there is something to derive it from.
            if (isset($withoutRecord[$student->id])) {
                $verdicts[$student->id] = null;

                continue;
            }

            $marks = $marksByStudent[$student->id] ?? [];
            $credited = $creditedByStudent[$student->id] ?? [];
            $reasons = [];

            foreach ($standardSubjectIds as $subjectId) {
                $placement = $placements->get($subjectId);
                if ($placement === null) {
                    continue;
                }

                if (isset($credited[$subjectId])) {
                    // A subject the student is already CREDITED for (a transferee credit, or a returnee's
                    // curriculum migration) cannot be enrolled again: the standard block has to lose it, so
                    // the student enrols per subject as an Irregular student (owner, 2026-10-15: a student
                    // credited for NSTP 1 was still treated as Regular and offered the whole block).
                    $reasons[] = [
                        'code' => 'needs_removing_credited',
                        'message' => "{$placement->subject->code} is already credited, so it is taken out of your block.",
                    ];

                    continue;
                }

                if ($this->isCompletedEarly($subjectId, $marks, $credited, $term->id)) {
                    // A subject already completed with a passing grade cannot be enrolled again in
                    // a block section (BuildEnrollmentBlockPool blocks it with 'partially_completed').
                    // It must be removed from the block load, making the student Irregular so they
                    // can select their remaining subjects individually. Subjects offered in either
                    // semester (coversBoth) remain flexible and exempt.
                    if (! SemesterCoverage::coversBoth($placement->semester)) {
                        $reasons[] = [
                            'code' => 'needs_removing_completed',
                            'message' => "{$placement->subject->code} has already been completed with a passing grade.",
                        ];
                    }

                    continue;
                }

                if (! $this->prerequisitesSatisfied($placement, $marks, $credited)) {
                    $reasons[] = [
                        'code' => 'needs_removing_prerequisite',
                        'message' => "{$placement->subject->code} cannot be taken yet — a prerequisite is not met.",
                    ];
                }
            }

            // Back subjects (ADR 0028): a required subject from an earlier point in the
            // curriculum that the student took in a PRIOR term and did not pass. It counts
            // whether or not a section is offered this term - the student is Irregular
            // either way, and enrolls it per subject when a section exists. A result
            // recorded in the current term is not a back subject yet (Doc 7: standing
            // changes from the next term), and a subject that is part of this term's own
            // block is a repeat inside the block, handled above.
            $backSubjectIds = [];

            foreach ($backlogSubjectIds as $subjectId) {
                $placement = $placements->get($subjectId);
                $entry = $marks[$subjectId] ?? null;

                if ($placement === null
                    || ! $placement->is_required
                    || $entry === null
                    || isset($credited[$subjectId])
                    || ! $entry['mark']->blocksRegularStanding()
                    || $entry['academic_term_id'] === $term->id) {
                    continue;
                }

                if (SemesterCoverage::primary($placement->semester)->ordinal($placement->year_level) >= $currentOrdinal) {
                    continue;
                }

                $backSubjectIds[$subjectId] = true;
                $reasons[] = [
                    'code' => 'needs_adding_backlog',
                    'message' => "{$placement->subject->code} was not passed in an earlier term and still needs to be retaken (back subject).",
                ];
            }

            foreach ($openBacklogSubjectIds as $subjectId) {
                $placement = $placements->get($subjectId);
                if ($placement === null
                    || ! $placement->is_required
                    || isset($backSubjectIds[$subjectId])
                    || $this->isCompleted($subjectId, $marks, $credited)) {
                    continue;
                }
                if (! $this->prerequisitesSatisfied($placement, $marks, $credited)) {
                    continue;
                }

                $placementOrdinal = SemesterCoverage::primary($placement->semester)->ordinal($placement->year_level);
                if ($placementOrdinal >= $currentOrdinal) {
                    // Current term or ahead of the student's current position — not a
                    // backlog item.
                    continue;
                }

                // If the subject is from the same year level (e.g. 1st sem of current year level),
                // it only becomes an actionable backlog if the student actually took and failed it in a prior term.
                // A regular student with passing grades in 1st semester must never flip to irregular in 2nd semester!
                if ($placement->year_level >= $student->year_level) {
                    $hasPriorFailure = isset($marks[$subjectId])
                        && ! $marks[$subjectId]['mark']->isPassing()
                        && $marks[$subjectId]['academic_term_id'] !== $term->id;

                    if (! $hasPriorFailure) {
                        continue;
                    }
                }

                $reasons[] = [
                    'code' => 'needs_adding_backlog',
                    'message' => "{$placement->subject->code} has an open section this term and still needs to be taken.",
                ];
            }

            $verdicts[$student->id] = match (true) {
                $reasons !== [] => ClassificationVerdict::irregular($reasons),
                ! $blockPublished => null,
                default => ClassificationVerdict::regular(),
            };
        }

        return $verdicts;
    }

    /**
     * @return list<int>
     */
    private function standardBlockSubjectIds(AcademicTerm $term, int $curriculumId, int $yearLevel): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            Section::query()
                ->where('academic_term_id', $term->id)
                ->where('status', SectionStatus::Published)
                ->where('is_block_exclusive', true)
                ->whereHas('sectionPlan', fn ($query) => $query
                    ->where('year_level', $yearLevel)
                    ->where('curriculum_id', $curriculumId))
                ->distinct()
                ->pluck('subject_id')
                ->all(),
        ));
    }

    /**
     * @param  list<int>  $candidateSubjectIds
     * @return list<int>
     */
    private function openNonBlockSectionSubjectIds(AcademicTerm $term, array $candidateSubjectIds): array
    {
        if ($candidateSubjectIds === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            Section::query()
                ->where('academic_term_id', $term->id)
                ->where('status', SectionStatus::Published)
                ->where(fn ($query) => $query->where('is_block_exclusive', false)->orWhereNull('is_block_exclusive'))
                ->whereIn('subject_id', $candidateSubjectIds)
                ->get(['id', 'subject_id', 'capacity', 'enrolled_count'])
                ->filter(fn (Section $section): bool => $section->remainingSeats() > 0)
                ->pluck('subject_id')
                ->unique()
                ->all(),
        ));
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<int, array<int, array{mark: GradeMark, academic_term_id: int}>>
     */
    private function latestLockedMarksByStudent(array $studentIds): array
    {
        $grades = AcademicGrade::query()
            ->whereIn('student_id', $studentIds)
            ->where('status', GradeStatus::Locked)
            ->orderBy('student_id')
            ->orderByDesc('academic_term_id')
            ->orderByDesc('id')
            ->get(['student_id', 'subject_id', 'academic_term_id', 'mark']);

        $marksByStudent = [];
        foreach ($grades as $grade) {
            if ($grade->mark === null) {
                continue;
            }
            $marksByStudent[$grade->student_id] ??= [];
            if (! array_key_exists($grade->subject_id, $marksByStudent[$grade->student_id])) {
                $marksByStudent[$grade->student_id][$grade->subject_id] = [
                    'mark' => $grade->mark,
                    'academic_term_id' => $grade->academic_term_id,
                ];
            }
        }

        return $marksByStudent;
    }

    /**
     * The students among `$studentIds` the system knows nothing about yet: no locked result, no
     * credited subject, no transferee credit on file (whatever its status) and no enrollment in an
     * earlier term. An enrollment in the term being classified is not a record: it is the very
     * enrollment this verdict is for, so a new student enrolling now stays undetermined.
     *
     * @param  list<int>  $studentIds
     * @param  array<int, array<int, array{mark: GradeMark, academic_term_id: int}>>  $marksByStudent
     * @param  array<int, array<int, true>>  $creditedByStudent
     * @return array<int, true> student id => true
     */
    private function studentIdsWithoutRecord(array $studentIds, AcademicTerm $term, array $marksByStudent, array $creditedByStudent): array
    {
        $candidates = array_values(array_filter(
            $studentIds,
            static fn (int $id): bool => ($marksByStudent[$id] ?? []) === [] && ($creditedByStudent[$id] ?? []) === [],
        ));

        if ($candidates === []) {
            return [];
        }

        $known = [];

        $withCredit = TransfereeCredit::query()->whereIn('student_id', $candidates)->distinct()->pluck('student_id');
        $withEarlierEnrollment = Enrollment::query()
            ->whereIn('student_id', $candidates)
            ->where('academic_term_id', '!=', $term->id)
            ->where('status', EnrollmentStatus::Enrolled->value)
            ->distinct()
            ->pluck('student_id');

        foreach ([...$withCredit->all(), ...$withEarlierEnrollment->all()] as $studentId) {
            $known[(int) $studentId] = true;
        }

        $without = [];

        foreach ($candidates as $id) {
            if (! isset($known[$id])) {
                $without[$id] = true;
            }
        }

        return $without;
    }

    /**
     * @param  list<int>  $studentIds
     * @return array<int, array<int, true>>
     */
    private function creditedSubjectIdsByStudent(array $studentIds, int $curriculumId): array
    {
        // Curriculum-migration credits and approved transferee credits alike
        // (ADR 0026): neither carries a GRC grade. A credit the Program Chair passed to the
        // Registrar Head counts too, provisionally (owner, 2026-10-15): the student enrols on it
        // before the Head decides, and the Head's rejection takes it back (`UpdateTransfereeCredit`).
        return (new ResolveCreditedSubjectIds)->forStudents($studentIds, $curriculumId, includeEndorsed: true);
    }

    /**
     * @param  array<int, array{mark: GradeMark, academic_term_id: int}>  $marks
     * @param  array<int, true>  $credited
     */
    private function isCompleted(int $subjectId, array $marks, array $credited): bool
    {
        if (isset($credited[$subjectId])) {
            return true;
        }

        $entry = $marks[$subjectId] ?? null;

        return $entry !== null && $entry['mark']->isPassing();
    }

    /**
     * @param  array<int, array{mark: GradeMark, academic_term_id: int}>  $marks
     * @param  array<int, true>  $credited
     */
    private function isCompletedEarly(int $subjectId, array $marks, array $credited, int $currentTermId): bool
    {
        if (isset($credited[$subjectId])) {
            return true;
        }

        $entry = $marks[$subjectId] ?? null;

        return $entry !== null && $entry['mark']->isPassing() && $entry['academic_term_id'] !== $currentTermId;
    }

    /**
     * @param  array<int, array{mark: GradeMark, academic_term_id: int}>  $marks
     * @param  array<int, true>  $credited
     */
    private function prerequisitesSatisfied(CurriculumSubject $placement, array $marks, array $credited): bool
    {
        foreach ($placement->prerequisites as $edge) {
            if (isset($credited[$edge->prerequisite_subject_id])) {
                continue;
            }

            $entry = $marks[$edge->prerequisite_subject_id] ?? null;
            $mark = $entry['mark'] ?? null;
            if ($mark?->isCompletion() === true) {
                continue;
            }

            $verdict = $this->evaluator->evaluate($mark?->value, $edge->minimum_grade);
            if ($verdict->status->value === 'not_satisfied') {
                return false;
            }
        }

        return true;
    }
}

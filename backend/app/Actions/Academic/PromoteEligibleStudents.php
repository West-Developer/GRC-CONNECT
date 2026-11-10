<?php

namespace App\Actions\Academic;

use App\Domain\Academic\GradeMark;
use App\Domain\Academic\GradeStatus;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Notifications\NotificationType;
use App\Models\AcademicGrade;
use App\Models\Notification;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Advances a student's year level (1st -> 2nd -> 3rd -> 4th Year) once every
 * required subject of their current year level has a FINAL locked grade
 * (docs/adr/0028-year-promotion-and-back-subjects.md).
 *
 * "Final" means credited, passed, or closed out with a failing mark: a failed
 * subject does not hold the student back, it becomes a back subject that
 * `ClassifyEnrollmentStanding` then treats as making them Irregular. A subject
 * with no locked grade yet, or whose latest mark is INC/DRP, is not final and
 * still holds promotion. This replaces the earlier "no failed subjects" rule
 * (stakeholder Doc 3, Part B) after the Doc 12 feedback: a student who
 * finished Year 1 with one failed subject is a 2nd Year student with one back
 * subject, not a permanent 1st Year.
 *
 * Two entry points share one rule: `execute()` sweeps everyone (the daily
 * `academic:promote-year-levels` command) and `promoteStudent()` handles one
 * student right after a grade is locked, so promotion no longer waits for the
 * scheduler.
 */
final readonly class PromoteEligibleStudents
{
    private const FINAL_YEAR_LEVEL = 4;

    public function __construct(
        private ?AuditRecorder $auditRecorder = null,
    ) {}

    /**
     * @return array{promoted_count: int, promoted: list<array{student_id: int, student_number: string, old_year_level: int, new_year_level: int, back_subject_codes: list<string>}>}
     */
    public function execute(
        ?int $programId = null,
        ?User $actor = null,
        ?AuditRequestContext $context = null,
        bool $dryRun = false,
    ): array {
        return DB::transaction(function () use ($programId, $actor, $context, $dryRun): array {
            $students = StudentProfile::query()
                ->where('year_level', '>=', 1)
                ->where('year_level', '<', self::FINAL_YEAR_LEVEL)
                ->when($programId !== null, fn ($q) => $q->where('program_id', $programId))
                ->with(['curriculum.subjectPlacements', 'user'])
                ->lockForUpdate()
                ->get();

            $promoted = [];

            foreach ($students as $student) {
                $record = $this->promoteLoaded($student, $actor, $context, $dryRun);

                if ($record !== null) {
                    $promoted[] = $record;
                }
            }

            return [
                'promoted_count' => count($promoted),
                'promoted' => $promoted,
            ];
        });
    }

    /**
     * Promotes one student if their current year is complete. Idempotent: the
     * next year's subjects have no grades yet, so a second call finds nothing
     * to do. The passed-in model's `year_level` is kept in step with the
     * database either way, because the caller (the grade-lock actions) hands
     * the same instance to the reclassifier straight afterwards.
     *
     * @return array{student_id: int, student_number: string, old_year_level: int, new_year_level: int, back_subject_codes: list<string>}|null
     */
    public function promoteStudent(
        StudentProfile $student,
        ?User $actor = null,
        ?AuditRequestContext $context = null,
    ): ?array {
        if ((int) $student->year_level < 1 || (int) $student->year_level >= self::FINAL_YEAR_LEVEL) {
            return null;
        }

        return DB::transaction(function () use ($student, $actor, $context): ?array {
            $locked = StudentProfile::query()
                ->with(['curriculum.subjectPlacements', 'user'])
                ->lockForUpdate()
                ->find($student->id);

            if ($locked === null) {
                return null;
            }

            $record = $this->promoteLoaded($locked, $actor, $context, false);

            $student->forceFill(['year_level' => $locked->year_level])->syncOriginalAttribute('year_level');

            return $record;
        });
    }

    /**
     * @return array{student_id: int, student_number: string, old_year_level: int, new_year_level: int, back_subject_codes: list<string>}|null
     */
    private function promoteLoaded(
        StudentProfile $student,
        ?User $actor,
        ?AuditRequestContext $context,
        bool $dryRun,
    ): ?array {
        // `student_profiles.curriculum_id` is NOT NULL with a restricting FK, so the
        // relation always resolves.
        $currentYearLevel = (int) $student->year_level;

        // All required subject IDs for the student's current year level across all semesters.
        /** @var list<int> $requiredSubjectIds */
        $requiredSubjectIds = $student->curriculum->subjectPlacements
            ->where('year_level', $currentYearLevel)
            ->where('is_required', true)
            ->pluck('subject_id')
            ->unique()
            ->values()
            ->all();

        if ($requiredSubjectIds === []) {
            return null;
        }

        // Subjects credited through a curriculum migration or an approved
        // transferee credit (ADR 0026), as a set keyed by subject id.
        $creditedSet = (new ResolveCreditedSubjectIds)
            ->forStudents([$student->id], $student->curriculum_id)[$student->id] ?? [];

        $marksBySubject = $this->lockedMarksBySubject($student->id, $requiredSubjectIds);

        $backSubjectIds = [];

        foreach ($requiredSubjectIds as $subjectId) {
            if (isset($creditedSet[$subjectId])) {
                continue;
            }

            // Newest attempt first; none at all means the subject was never graded.
            $marks = $marksBySubject[$subjectId] ?? [];

            if ($marks === []) {
                return null;
            }

            if ($this->hasPassingMark($marks)) {
                continue;
            }

            // Not passed: it only counts as closed out (a back subject) when the
            // latest attempt is a final failing mark. INC and DRP are not final.
            if ($marks[0] === GradeMark::Failed || $marks[0] === GradeMark::NotComplete) {
                $backSubjectIds[] = $subjectId;

                continue;
            }

            return null;
        }

        $newYearLevel = $currentYearLevel + 1;

        /** @var list<string> $backSubjectCodes */
        $backSubjectCodes = $backSubjectIds === []
            ? []
            : Subject::query()->whereIn('id', $backSubjectIds)->orderBy('code')->pluck('code')->all();

        if (! $dryRun) {
            $before = ['year_level' => $currentYearLevel];
            $student->update(['year_level' => $newYearLevel]);
            $after = ['year_level' => $newYearLevel];

            if ($actor !== null && $context !== null && $this->auditRecorder !== null) {
                $this->auditRecorder->record(
                    $actor,
                    AuditAction::STUDENT_PROFILE_UPDATED,
                    AuditableType::STUDENT_PROFILE,
                    $student->id,
                    $before,
                    $after,
                    null,
                    $context,
                );
            }

            Notification::create([
                'user_id' => $student->user_id,
                'type' => NotificationType::StudentYearLevelPromoted,
                'message' => $this->message($currentYearLevel, $newYearLevel, $backSubjectCodes),
            ]);
        }

        return [
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'old_year_level' => $currentYearLevel,
            'new_year_level' => $newYearLevel,
            'back_subject_codes' => $backSubjectCodes,
        ];
    }

    /**
     * Locked marks per required subject, newest attempt first. A locked grade
     * with no mark (nothing to judge) is skipped, as in the classifier.
     *
     * @param  list<int>  $subjectIds
     * @return array<int, list<GradeMark>>
     */
    private function lockedMarksBySubject(int $studentId, array $subjectIds): array
    {
        $grades = AcademicGrade::query()
            ->where('student_id', $studentId)
            ->where('status', GradeStatus::Locked)
            ->whereIn('subject_id', $subjectIds)
            ->orderByDesc('academic_term_id')
            ->orderByDesc('id')
            ->get(['subject_id', 'mark']);

        $bySubject = [];

        foreach ($grades as $grade) {
            if (! $grade->mark instanceof GradeMark) {
                continue;
            }

            $bySubject[$grade->subject_id][] = $grade->mark;
        }

        return $bySubject;
    }

    /**
     * @param  list<GradeMark>  $marks
     */
    private function hasPassingMark(array $marks): bool
    {
        foreach ($marks as $mark) {
            if ($mark->isPassing()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $backSubjectCodes
     */
    private function message(int $fromYear, int $toYear, array $backSubjectCodes): string
    {
        if ($backSubjectCodes === []) {
            return "Congratulations! You have completed all requirements for Year {$fromYear} and have been promoted to Year {$toYear}.";
        }

        $list = implode(', ', $backSubjectCodes);

        return "You have finished Year {$fromYear} and moved up to Year {$toYear}. Back subject(s) to retake: {$list}.";
    }
}

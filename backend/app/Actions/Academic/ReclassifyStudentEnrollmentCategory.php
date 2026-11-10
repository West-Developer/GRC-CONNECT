<?php

namespace App\Actions\Academic;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\ClassificationVerdict;
use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Notifications\NotificationType;
use App\Models\AcademicTerm;
use App\Models\Notification;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Derives and, when it has changed, writes a student's Regular/Irregular
 * `enrollment_category` for the current term — see `ClassifyEnrollmentStanding`
 * for the term-scoped rule itself. This is always audited and the student is
 * always notified, because `enrollment_category` feeds
 * `EnrollmentAudience::forStudent()`, which gates which block-section window
 * a student may enroll through: a category flip is a change to that
 * student's enrollment eligibility, not a cosmetic label update.
 *
 * `executeMany()` is the batch entry point `execute()` itself delegates to.
 * Students are grouped by `(curriculum_id, year_level)` — the unit
 * `ClassifyEnrollmentStanding::classifyMany()` requires — so the read queries
 * it runs are shared across every student in a group instead of repeated per
 * student, then at most two bulk `UPDATE`s are issued (one per resulting
 * category). Audit rows and notifications are written only for students
 * whose category actually changed; a student whose standing is undetermined
 * (no block published yet for their year level this term) is carried forward
 * unchanged and never written, audited, or notified.
 */
final readonly class ReclassifyStudentEnrollmentCategory
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private ClassifyEnrollmentStanding $classifier,
    ) {}

    public function execute(
        StudentProfile $student,
        AcademicTerm $currentTerm,
        User $actor,
        AuditRequestContext $context,
    ): ClassificationVerdict {
        $verdicts = $this->executeMany(new Collection([$student]), $currentTerm, $actor, $context);

        return $verdicts[$student->id];
    }

    /**
     * @param  Collection<int, StudentProfile>  $students
     * @return array<int, ClassificationVerdict>
     */
    public function executeMany(
        Collection $students,
        AcademicTerm $currentTerm,
        User $actor,
        AuditRequestContext $context,
    ): array {
        if ($students->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($students, $currentTerm, $actor, $context): array {
            [$verdicts, $toRegularIds, $toIrregularIds, ] = $this->computeVerdicts($students, $currentTerm);

            $changedIds = [...$toRegularIds, ...$toIrregularIds];

            if ($changedIds === []) {
                return $verdicts;
            }

            $now = Carbon::now();

            if ($toRegularIds !== []) {
                StudentProfile::query()->whereIn('id', $toRegularIds)->update([
                    'enrollment_category' => EnrollmentCategory::Regular->value,
                    'enrollment_category_derived_at' => $now,
                ]);
            }

            if ($toIrregularIds !== []) {
                StudentProfile::query()->whereIn('id', $toIrregularIds)->update([
                    'enrollment_category' => EnrollmentCategory::Irregular->value,
                    'enrollment_category_derived_at' => $now,
                ]);
            }

            foreach ($students as $student) {
                if (! in_array($student->id, $changedIds, true)) {
                    continue;
                }

                $this->auditAndNotify($student, $verdicts[$student->id], $actor, $context);
            }

            return $verdicts;
        });
    }

    /**
     * Read-only: computes what `executeMany()` would decide, without
     * writing, auditing, or notifying anything. Used by the
     * `students:reclassify --dry-run` command to report a preview.
     *
     * @param  Collection<int, StudentProfile>  $students
     * @return array<int, ClassificationVerdict>
     */
    public function preview(Collection $students, AcademicTerm $currentTerm): array
    {
        [$verdicts] = $this->computeVerdicts($students, $currentTerm);

        return $verdicts;
    }

    /**
     * Same as preview(), but also returns which student ids were undetermined
     * (no block published yet) — needed by `students:reclassify` so it can
     * avoid reporting a "no write, category left untouched" outcome as a
     * category change. Not used by any other caller.
     *
     * @param  Collection<int, StudentProfile>  $students
     * @return array{0: array<int, ClassificationVerdict>, 1: list<int>}
     */
    public function previewWithUndetermined(Collection $students, AcademicTerm $currentTerm): array
    {
        [$verdicts, , , $undeterminedIds] = $this->computeVerdicts($students, $currentTerm);

        return [$verdicts, $undeterminedIds];
    }

    /**
     * Same as executeMany(), but also returns which student ids were
     * undetermined — see previewWithUndetermined()'s docblock for why.
     *
     * @param  Collection<int, StudentProfile>  $students
     * @return array{0: array<int, ClassificationVerdict>, 1: list<int>}
     */
    public function executeManyWithUndetermined(
        Collection $students,
        AcademicTerm $currentTerm,
        User $actor,
        AuditRequestContext $context,
    ): array {
        [, , , $undeterminedIds] = $this->computeVerdicts($students, $currentTerm);
        $verdicts = $this->executeMany($students, $currentTerm, $actor, $context);

        return [$verdicts, $undeterminedIds];
    }

    /**
     * @param  Collection<int, StudentProfile>  $students
     * @return array{0: array<int, ClassificationVerdict>, 1: list<int>, 2: list<int>, 3: list<int>}
     */
    private function computeVerdicts(Collection $students, AcademicTerm $currentTerm): array
    {
        if ($students->isEmpty()) {
            return [[], [], [], []];
        }

        $verdicts = [];
        $toRegularIds = [];
        $toIrregularIds = [];
        $undeterminedIds = [];

        $groups = $students->groupBy(
            static fn (StudentProfile $student): string => $student->curriculum_id.':'.$student->year_level,
        );

        foreach ($groups as $group) {
            $groupVerdicts = $this->classifier->classifyMany($group, $currentTerm);

            foreach ($group as $student) {
                $verdict = $groupVerdicts[$student->id];

                if ($verdict === null) {
                    // Undetermined (no block published yet) — carry the
                    // student's current category forward unchanged, and
                    // never write/audit/notify for them.
                    $verdicts[$student->id] = $student->enrollment_category === EnrollmentCategory::Irregular->value
                        ? ClassificationVerdict::irregular([])
                        : ClassificationVerdict::regular();
                    $undeterminedIds[] = $student->id;

                    continue;
                }

                $verdicts[$student->id] = $verdict;

                if ($student->enrollment_category === $verdict->category->value) {
                    continue;
                }

                if ($verdict->isRegular()) {
                    $toRegularIds[] = $student->id;
                } else {
                    $toIrregularIds[] = $student->id;
                }
            }
        }

        return [$verdicts, $toRegularIds, $toIrregularIds, $undeterminedIds];
    }

    private function auditAndNotify(
        StudentProfile $student,
        ClassificationVerdict $verdict,
        User $actor,
        AuditRequestContext $context,
    ): void {
        $this->auditRecorder->record(
            $actor,
            AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED,
            AuditableType::STUDENT_PROFILE,
            $student->id,
            ['enrollment_category' => $student->enrollment_category],
            [
                'enrollment_category' => $verdict->category->value,
                'reasons' => $verdict->reasons,
            ],
            null,
            $context,
        );

        $message = $verdict->isRegular()
            ? 'Your enrollment standing has been updated to Regular.'
            : sprintf(
                'Your enrollment standing has been updated to Irregular: %s',
                implode(' ', array_column($verdict->reasons, 'message')),
            );

        Notification::create([
            'user_id' => $student->user_id,
            'type' => NotificationType::EnrollmentCategoryReclassified,
            'message' => $message,
        ]);
    }
}

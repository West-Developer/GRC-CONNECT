<?php

use App\Actions\Academic\ClassifyEnrollmentStanding;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentCategory;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AcademicTerm;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time correction for a handful of real students left `Irregular` by a classifier rule that no
 * longer exists: an earlier iteration of `ClassifyEnrollmentStanding` flagged an already-completed
 * subject as a reason to go Irregular ("needs_removing_completed"); the current rule explicitly
 * treats a passed subject as a normal achievement and never a cause for Irregular standing (see that
 * class's own docblock). Reclassification cannot fix these on its own: when a term has no published
 * block yet for a student's curriculum/year, the verdict is `null` ("undetermined") and the action
 * deliberately carries the stored value forward unchanged rather than overwriting it — the right
 * behaviour in general, but it means a value written by the old, since-fixed rule survives forever.
 *
 * Scope is deliberately narrow and evidence-based, not "every Irregular student": only a
 * `student_profiles` row with `enrollment_category = 'irregular'`, zero rows in `enrollments`, and
 * zero rows in `academic_grades` ever. A student with real academic history is left untouched here,
 * even if their current verdict happens to be undetermined too — reclassifying those on real
 * evidence is `ReclassifyStudentEnrollmentCategory`'s job, not this migration's.
 */
return new class extends Migration
{
    public function up(): void
    {
        $actor = User::query()
            ->whereIn('role', [UserRole::ItAdmin->value, UserRole::RegistrarHead->value])
            ->where('status', UserStatus::Active->value)
            ->orderByRaw("role = 'it_admin' desc")
            ->first();
        $term = AcademicTerm::query()->where('status', 'semester_ongoing')->first();

        if ($actor === null || $term === null) {
            return;
        }

        $candidates = StudentProfile::query()
            ->where('enrollment_category', EnrollmentCategory::Irregular->value)
            ->whereDoesntHave('enrollments')
            ->whereNotExists(fn ($query) => $query->selectRaw(1)
                ->from('academic_grades')
                ->whereColumn('academic_grades.student_id', 'student_profiles.id'))
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        $classifier = app(ClassifyEnrollmentStanding::class);
        $auditRecorder = app(AuditRecorder::class);
        $context = new AuditRequestContext('migration-2026_09_28_000001', null);

        DB::transaction(function () use ($candidates, $classifier, $term, $auditRecorder, $actor, $context): void {
            foreach ($candidates->groupBy(fn (StudentProfile $s): string => $s->curriculum_id.':'.$s->year_level) as $group) {
                $verdicts = $classifier->classifyMany($group, $term);

                foreach ($group as $student) {
                    $verdict = $verdicts[$student->id];
                    // Zero enrollments and zero grades ever: there is no real evidence left to
                    // justify Irregular, whether the fresh verdict is a determinate Regular or an
                    // undetermined null (no block published yet for this curriculum/year).
                    $newCategory = $verdict?->category->value;

                    if ($newCategory === EnrollmentCategory::Irregular->value) {
                        continue;
                    }

                    $before = ['enrollment_category' => $student->enrollment_category];
                    $student->forceFill([
                        'enrollment_category' => $newCategory,
                        'enrollment_category_derived_at' => $newCategory !== null ? now() : null,
                    ])->save();

                    $auditRecorder->record(
                        $actor,
                        AuditAction::STUDENT_ENROLLMENT_CATEGORY_RECLASSIFIED,
                        AuditableType::STUDENT_PROFILE,
                        $student->id,
                        $before,
                        [
                            'enrollment_category' => $newCategory,
                            'reasons' => $verdict?->reasons ?? [],
                            'correction' => 'stale value from a classifier rule removed before this fix; student has no enrollment or grade history',
                        ],
                        'Data correction: resync of a pre-fix stale Irregular classification (migration 2026_09_28_000001).',
                        $context,
                    );
                }
            }
        });
    }

    public function down(): void
    {
        // A data correction, not a schema change: there is nothing structural to reverse, and the
        // audit trail this writes (student_profile.enrollment_category_reclassified) is the record
        // of what changed and why, the same way the 2026-09-27 name-parts backfill's down() explains.
    }
};

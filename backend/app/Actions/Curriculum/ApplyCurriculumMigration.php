<?php

namespace App\Actions\Curriculum;

use App\Actions\Academic\ReclassifyStudentEnrollmentCategory;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\CurriculumMigration;
use App\Models\CurriculumMigrationCredit;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ApplyCurriculumMigration
{
    public function __construct(
        private PreviewCurriculumMigration $preview,
        private AuditRecorder $auditRecorder,
        private ReclassifyStudentEnrollmentCategory $reclassifier,
    ) {}

    /** @param list<int> $equivalencyIds */
    public function execute(User $actor, Curriculum $target, StudentProfile $student, array $equivalencyIds, AuditRequestContext $context): CurriculumMigration
    {
        return DB::transaction(function () use ($actor, $target, $student, $equivalencyIds, $context): CurriculumMigration {
            $lockedTarget = Curriculum::query()->with(['program', 'equivalencySourceCurriculum'])->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $lockedStudent = StudentProfile::query()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            $preview = $this->preview->execute($actor, $lockedTarget, $lockedStudent);
            $candidatesById = collect($preview['credit_candidates'])->keyBy(static fn (array $candidate): int => $candidate['equivalency']->id);
            $selectedIds = array_values(array_unique($equivalencyIds));

            if (count($selectedIds) !== count($equivalencyIds) || collect($selectedIds)->diff($candidatesById->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['equivalency_ids' => 'Select only currently qualified equivalencies from this migration preview.']);
            }

            $migration = CurriculumMigration::create([
                'student_id' => $lockedStudent->id,
                'source_curriculum_id' => $preview['source_curriculum']->id,
                'target_curriculum_id' => $lockedTarget->id,
                'processed_by' => $actor->id,
                'migrated_at' => now(),
            ]);
            foreach ($selectedIds as $equivalencyId) {
                $candidate = $candidatesById->get($equivalencyId);
                if ($candidate === null) {
                    throw ValidationException::withMessages([
                        'equivalency_ids' => 'Select only currently qualified equivalencies from this migration preview.',
                    ]);
                }

                CurriculumMigrationCredit::create([
                    'curriculum_migration_id' => $migration->id,
                    'curriculum_subject_equivalency_id' => $candidate['equivalency']->id,
                    'source_academic_grade_id' => $candidate['grade']->id,
                    'target_subject_id' => $candidate['equivalency']->target_subject_id,
                ]);
            }

            $lockedStudent->update(['curriculum_id' => $lockedTarget->id]);
            $this->auditRecorder->record(
                $actor,
                AuditAction::STUDENT_CURRICULUM_MIGRATED,
                AuditableType::STUDENT_PROFILE,
                $lockedStudent->id,
                ['curriculum_id' => $preview['source_curriculum']->id],
                [
                    'curriculum_id' => $lockedTarget->id,
                    'migration_id' => $migration->id,
                    'credited_subject_ids' => $candidatesById->only($selectedIds)->map(static fn (array $candidate): int => $candidate['equivalency']->target_subject_id)->values()->all(),
                ],
                null,
                $context,
            );

            // The credit changes which completed target subjects count for
            // Regular/Irregular standing. Use the same current-term boundary
            // as locked-grade updates; between terms there is no safe basis
            // for recomputing standing, so the scheduled reclassifier will
            // pick it up once a term is ongoing.
            $currentTerm = AcademicTerm::query()
                ->where('status', AcademicTermStatus::SemesterOngoing)
                ->first();
            if ($currentTerm !== null) {
                $currentStudent = StudentProfile::query()
                    ->whereKey($lockedStudent->id)
                    ->firstOrFail();
                $this->reclassifier->execute($currentStudent, $currentTerm, $actor, $context);
            }

            return $migration->load('credits');
        });
    }
}

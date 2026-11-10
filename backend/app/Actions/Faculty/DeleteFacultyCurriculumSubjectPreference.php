<?php

namespace App\Actions\Faculty;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\FacultyCurriculumSubjectPreference;
use App\Models\FacultySpecialization;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class DeleteFacultyCurriculumSubjectPreference
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly DeleteFacultySpecialization $deleteSpecialization,
    ) {}

    public function execute(User $actor, FacultyCurriculumSubjectPreference $preference, AuditRequestContext $context): void
    {
        DB::transaction(function () use ($actor, $preference, $context): void {
            $beforeValues = CreateFacultyCurriculumSubjectPreference::snapshot($preference);
            $preference->delete();
            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_CURRICULUM_SUBJECT_PREFERENCE_DELETED,
                AuditableType::FACULTY_CURRICULUM_SUBJECT_PREFERENCE,
                $preference->id,
                $beforeValues,
                null,
                null,
                $context,
            );

            $this->removeOrphanedSpecialization($actor, $preference, $context);
        });
    }

    /**
     * The proficiency a professor declared while saving a preference goes with it:
     * once no preference of theirs names the subject any more, their declared
     * specialization for it is removed too. Seeded (workbook) specializations
     * are evidence, not something the professor declared, so they stay.
     */
    private function removeOrphanedSpecialization(User $actor, FacultyCurriculumSubjectPreference $preference, AuditRequestContext $context): void
    {
        $stillPreferred = FacultyCurriculumSubjectPreference::query()
            ->where('professor_id', $preference->professor_id)
            ->where('subject_id', $preference->subject_id)
            ->exists();

        if ($stillPreferred) {
            return;
        }

        $specialization = FacultySpecialization::query()
            ->where('professor_id', $preference->professor_id)
            ->where('subject_id', $preference->subject_id)
            ->where('source', 'declared')
            ->first();

        if ($specialization !== null) {
            $this->deleteSpecialization->execute($actor, $specialization, $context);
        }
    }
}

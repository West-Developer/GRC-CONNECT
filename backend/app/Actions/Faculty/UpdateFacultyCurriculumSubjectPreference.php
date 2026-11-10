<?php

namespace App\Actions\Faculty;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\FacultyCurriculumSubjectPreference;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class UpdateFacultyCurriculumSubjectPreference
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /** @param array{curriculum_id: int, subject_id: int, semester: string, rank: int} $validatedData */
    public function execute(User $actor, array $validatedData, FacultyCurriculumSubjectPreference $preference, AuditRequestContext $context): FacultyCurriculumSubjectPreference
    {
        return DB::transaction(function () use ($actor, $validatedData, $preference, $context): FacultyCurriculumSubjectPreference {
            $beforeValues = CreateFacultyCurriculumSubjectPreference::snapshot($preference);
            $preference->update([...$validatedData, 'origin' => 'declared']);
            $preference->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_CURRICULUM_SUBJECT_PREFERENCE_UPDATED,
                AuditableType::FACULTY_CURRICULUM_SUBJECT_PREFERENCE,
                $preference->id,
                $beforeValues,
                CreateFacultyCurriculumSubjectPreference::snapshot($preference),
                null,
                $context,
            );

            return $preference;
        });
    }
}

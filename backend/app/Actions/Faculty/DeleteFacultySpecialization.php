<?php

namespace App\Actions\Faculty;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\FacultySpecialization;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class DeleteFacultySpecialization
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function execute(User $actor, FacultySpecialization $specialization, AuditRequestContext $context): void
    {
        DB::transaction(function () use ($actor, $specialization, $context): void {
            $beforeValues = CreateFacultySpecialization::snapshot($specialization);
            $specialization->delete();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_SPECIALIZATION_DELETED,
                AuditableType::FACULTY_SPECIALIZATION,
                $specialization->id,
                $beforeValues,
                null,
                null,
                $context,
            );
        });
    }
}

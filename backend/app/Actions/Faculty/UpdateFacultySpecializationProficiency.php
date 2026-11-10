<?php

namespace App\Actions\Faculty;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Faculty\FacultySpecializationStatus;
use App\Domain\Faculty\SpecializationProficiency;
use App\Models\FacultySpecialization;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Lets a professor change the proficiency of a specialization they declared.
 * A real change is a new claim, so a decided (approved/rejected) specialization
 * goes back to pending for the Program Chair to review again; saving the same
 * value is a no-op.
 */
final class UpdateFacultySpecializationProficiency
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function execute(
        User $actor,
        FacultySpecialization $specialization,
        SpecializationProficiency $proficiency,
        AuditRequestContext $context,
    ): FacultySpecialization {
        return DB::transaction(function () use ($actor, $specialization, $proficiency, $context): FacultySpecialization {
            $locked = FacultySpecialization::query()->whereKey($specialization->id)->lockForUpdate()->firstOrFail();

            if ($locked->proficiency === $proficiency) {
                return $locked;
            }

            $before = CreateFacultySpecialization::snapshot($locked);

            $locked->update([
                'proficiency' => $proficiency,
                'status' => FacultySpecializationStatus::Pending,
                'decided_by' => null,
                'decided_at' => null,
                'decision_reason' => null,
            ]);
            $locked->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_SPECIALIZATION_UPDATED,
                AuditableType::FACULTY_SPECIALIZATION,
                $locked->id,
                $before,
                CreateFacultySpecialization::snapshot($locked),
                null,
                $context,
            );

            return $locked;
        });
    }
}

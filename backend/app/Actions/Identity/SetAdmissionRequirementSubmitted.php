<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\AdmissionRequirementCategory;
use App\Models\AdmissionRequirementType;
use App\Models\StudentAdmissionRequirement;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ticks or unticks one requirement for one student (Admission Staff; ADR 0037).
 * Setting a requirement to the state it already has changes nothing and writes
 * no audit row, so a repeated request is safe. Every real change is audited
 * with what it was and what it became.
 */
final readonly class SetAdmissionRequirementSubmitted
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @return bool whether anything changed
     */
    public function execute(
        StudentProfile $student,
        AdmissionRequirementType $type,
        bool $isSubmitted,
        User $actor,
        AuditRequestContext $context,
    ): bool {
        $applicable = AdmissionRequirementCategory::forStudentType($student->student_type);

        if (! $type->is_active || ! in_array($type->category, $applicable, true)) {
            throw ValidationException::withMessages([
                'requirement_type_id' => 'That requirement does not apply to this student.',
            ]);
        }

        return DB::transaction(function () use ($student, $type, $isSubmitted, $actor, $context): bool {
            $record = StudentAdmissionRequirement::query()
                ->where('student_profile_id', $student->id)
                ->where('requirement_type_id', $type->id)
                ->lockForUpdate()
                ->first();

            $was = $record instanceof StudentAdmissionRequirement && $record->is_submitted;

            if ($was === $isSubmitted) {
                return false;
            }

            $attributes = [
                'is_submitted' => $isSubmitted,
                'submitted_at' => $isSubmitted ? now() : null,
                'recorded_by' => $actor->id,
            ];

            if ($record instanceof StudentAdmissionRequirement) {
                $record->update($attributes);
            } else {
                $record = StudentAdmissionRequirement::create([
                    'student_profile_id' => $student->id,
                    'requirement_type_id' => $type->id,
                    ...$attributes,
                ]);
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::ADMISSION_REQUIREMENT_UPDATED,
                AuditableType::STUDENT_ADMISSION_REQUIREMENT,
                $record->id,
                ['student_profile_id' => $student->id, 'requirement' => $type->name, 'is_submitted' => $was],
                ['student_profile_id' => $student->id, 'requirement' => $type->name, 'is_submitted' => $isSubmitted],
                null,
                $context,
            );

            return true;
        });
    }
}

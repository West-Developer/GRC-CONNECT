<?php

namespace App\Actions\Organization;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\FacultyEmploymentType;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class UpdateFacultyWorkforceProfile
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    /** @param array{status: string, employment_type: string, reason?: ?string} $validatedData */
    public function execute(User $actor, User $facultyMember, array $validatedData, AuditRequestContext $context): User
    {
        return DB::transaction(function () use ($actor, $facultyMember, $validatedData, $context): User {
            $beforeValues = self::snapshot($facultyMember);
            $facultyMember->update([
                'status' => UserStatus::from($validatedData['status']),
                'employment_type' => FacultyEmploymentType::from($validatedData['employment_type']),
            ]);
            $facultyMember->refresh();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_WORKFORCE_PROFILE_UPDATED,
                AuditableType::FACULTY_WORKFORCE_PROFILE,
                $facultyMember->id,
                $beforeValues,
                self::snapshot($facultyMember),
                $validatedData['reason'] ?? null,
                $context,
            );

            return $facultyMember;
        });
    }

    /** @return array{status: string, employment_type: ?string} */
    private static function snapshot(User $facultyMember): array
    {
        return [
            'status' => $facultyMember->status->value,
            'employment_type' => $facultyMember->employment_type?->value,
        ];
    }
}

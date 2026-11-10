<?php

namespace App\Actions\SuperAdmin;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeUserRole
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function execute(
        User $target,
        UserRole $newRole,
        ?CollegeCode $college,
        string $reason,
        User $actor,
        AuditRequestContext $context,
    ): User {
        if (! in_array($target->role, UserRole::superAdminInvitableCases(), true)) {
            throw ValidationException::withMessages([
                'role' => ['Only staff accounts can have their role changed.'],
            ]);
        }

        if ($target->role === UserRole::Faculty) {
            $hasActiveSections = Section::query()
                ->where('professor_id', $target->id)
                ->whereHas('academicTerm', fn ($q) => $q->where('status', '!=', AcademicTermStatus::Archived))
                ->exists();

            if ($hasActiveSections) {
                abort(409, 'Reassign their sections first.');
            }
        }

        $updated = DB::transaction(function () use ($target, $newRole, $college, $reason, $actor, $context): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->role, UserRole::superAdminInvitableCases(), true)) {
                throw ValidationException::withMessages([
                    'role' => ['Only staff accounts can have their role changed.'],
                ]);
            }

            $before = [
                'role' => $locked->role->value,
                'college' => $locked->college?->value,
            ];

            $requiresCollege = in_array(
                $newRole,
                [UserRole::Dean, UserRole::ProgramChair, UserRole::Faculty],
                true
            );

            $after = [
                'role' => $newRole->value,
                'college' => $requiresCollege ? $college?->value : null,
            ];

            $locked->forceFill([
                'role' => $newRole,
                'college' => $requiresCollege ? $college : null,
            ])->save();

            $locked->tokens()->delete();

            $this->auditRecorder->record(
                $actor,
                AuditAction::USER_ACCOUNT_ROLE_CHANGED,
                AuditableType::STAFF_ACCOUNT,
                $locked->id,
                $before,
                $after,
                $reason,
                $context,
            );

            return $locked->refresh();
        });

        return $updated;
    }
}
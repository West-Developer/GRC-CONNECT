<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a pending staff account (name is a placeholder — the invitee
 * supplies their real name when they redeem the setup code, the same
 * pattern `InviteFacultyAccount` already established) and sends the setup
 * invitation immediately. Registrar Head only, for any role
 * `UserRole::registrarInvitableCases()` allows — every staff/leadership
 * role except Student (Admission's own flow already owns that) and
 * AdmissionStaff.
 */
final class InviteStaffAccount
{
    public function __construct(private readonly SendStaffAccountSetupInvitation $sendInvitation) {}

    public function handle(
        string $email,
        UserRole $role,
        User $actor,
        AuditRequestContext $context,
        ?CollegeCode $college = null,
        ?string $mastersDegree = null,
        ?array $allowedRoles = null,
    ): User {
        $allowed = $allowedRoles ?? UserRole::registrarInvitableCases();

        if (! in_array($role, $allowed, true)) {
            throw ValidationException::withMessages([
                'role' => 'This role cannot be invited through this form.',
            ]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This email is already registered.',
            ]);
        }

        $placeholderName = explode('@', $email)[0];

        $staff = User::create([
            'name' => $placeholderName,
            'email' => $email,
            'password' => Str::random(64),
            'role' => $role,
            'college' => $college,
            'masters_degree' => $mastersDegree,
            'status' => UserStatus::Disabled,
            'account_setup_completed_at' => null,
        ]);

        $this->sendInvitation->handle($staff, $actor, $context);

        return $staff->refresh();
    }
}

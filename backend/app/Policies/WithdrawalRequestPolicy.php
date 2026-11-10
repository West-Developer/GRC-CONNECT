<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * PRD §3.8 assigns "process dropping and withdrawal requests" to Registrar
 * Staff. Stakeholder Doc 14 ("Withdrawal is missing to the Registrar Head")
 * gives the Registrar Head the same decision, so both may `decide`; the
 * Registrar Head also reads every request (`viewAny`), the same "keeper of
 * the official record" visibility it has over enrollments and grades. "Which
 * rows" is resolved by `WithdrawalRequest::scopeVisibleTo` — this Policy is
 * the role-level gate.
 */
final class WithdrawalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Student,
            UserRole::RegistrarStaff,
            UserRole::RegistrarHead,
        ], true);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    public function decide(User $user): bool
    {
        return in_array($user->role, [UserRole::RegistrarStaff, UserRole::RegistrarHead], true);
    }
}

<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * Explicit user requirement (Phase 7): a Student submits an add/drop/
 * change-section request with a reason; the Registrar HEAD decides it — not
 * Registrar Staff, the opposite assignment from `WithdrawalRequestPolicy`;
 * Registrar Staff only reads every request, the same "keeper of the record"
 * visibility it already has elsewhere. "Which rows" is resolved by
 * `EnrollmentChangeRequest::scopeVisibleTo` — this Policy is the role-level
 * gate.
 */
final class EnrollmentChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Student,
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
        ], true);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    public function decide(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }
}

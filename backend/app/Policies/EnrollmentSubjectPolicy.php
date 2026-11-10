<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * PRD §3.2 ("View assigned teaching schedules and class rosters") and
 * §3.8 (Registrar Staff/Head as keepers of the official record): only
 * Faculty, Registrar Staff, and Registrar Head may read the class roster.
 * No Student-facing use case exists for this endpoint. "Which rows" is
 * resolved by `EnrollmentSubject::scopeVisibleTo` — this Policy is the
 * role-level gate.
 */
final class EnrollmentSubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Faculty,
            UserRole::RegistrarStaff,
            UserRole::RegistrarHead,
        ], true);
    }
}

<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

final class FacultyMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return ($user->role === UserRole::ProgramChair && $user->college !== null)
            || $user->role === UserRole::RegistrarHead
            || $user->role === UserRole::Faculty;
    }

    /**
     * The Registrar Head reads any professor's teaching profile (stakeholder
     * Doc 14). Read-only; the Program Head's own workforce edits stay above.
     */
    public function viewProfile(User $user, User $facultyMember): bool
    {
        return $user->role === UserRole::RegistrarHead
            && $facultyMember->role === UserRole::Faculty;
    }

    public function updateWorkforceProfile(User $user, User $facultyMember): bool
    {
        return $user->role === UserRole::ProgramChair
            && $user->college !== null
            && $facultyMember->role === UserRole::Faculty
            && $facultyMember->college === $user->college;
    }
}

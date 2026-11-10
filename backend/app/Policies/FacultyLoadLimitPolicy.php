<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * Who may set teaching-load limits and per-professor overrides (stakeholder
 * Doc 14, ADR 0033): the Program Head and the Dean, each for their own
 * college only. The college scope is applied by the actions, which read it
 * from the acting user rather than from the request.
 */
final class FacultyLoadLimitPolicy
{
    public function manage(User $user): bool
    {
        return in_array($user->role, [UserRole::ProgramChair, UserRole::Dean], true)
            && $user->college !== null;
    }
}

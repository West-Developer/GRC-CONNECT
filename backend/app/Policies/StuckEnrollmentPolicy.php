<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * Narrower than DashboardPolicy on purpose: PRD §3.5 authorizes only the
 * Dean to see "stuck-student reports" — not Executive Director, who gets
 * aggregate-only dashboards and no student-level enrollment data at all.
 */
final class StuckEnrollmentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Dean, UserRole::RegistrarHead], true);
    }
}

<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * The eligible-subject pool is a student's own computed view, not a stored
 * resource another role can query on their behalf — no broader role-based
 * visibility, matching StudentProfilePolicy.
 */
final class EligibleSubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Student;
    }
}

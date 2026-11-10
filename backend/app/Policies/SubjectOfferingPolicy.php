<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

/**
 * Planning-only data — unlike Curriculum/Subject/Program there is no
 * learner-visibility concept here at all; only the Program Chair who plans
 * capacity ever needs to read or write it.
 */
final class SubjectOfferingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::ProgramChair;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::ProgramChair;
    }
}

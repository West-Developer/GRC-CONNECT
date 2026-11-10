<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\AcademicTerm;
use App\Models\User;

final class AcademicTermPolicy
{
    /**
     * Every role may list academic terms; AcademicTerm::scopeVisibleTo()
     * controls which rows a learner-scoped role actually receives.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Actor: Registrar Head — PRD Process 1's term-creation owner.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }

    public function update(User $user, AcademicTerm $term): bool
    {
        return $user->role === UserRole::RegistrarHead;
    }

    /**
     * A learner-scoped role may not read a non-learner-visible term even by
     * direct ID lookup; planning roles may read any term.
     */
    public function view(User $user, AcademicTerm $term): bool
    {
        if (! $user->role->isLearnerScoped()) {
            return true;
        }

        return $term->status->isVisibleToLearners();
    }
}

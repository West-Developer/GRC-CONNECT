<?php

namespace App\Policies;

use App\Models\Program;
use App\Models\User;

final class ProgramPolicy
{
    /**
     * Every role may list programs; Program::scopeVisibleTo() controls which
     * rows a learner-scoped role actually receives in the response.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A learner-scoped role may not read a non-learner-visible program even
     * by direct ID lookup; planning roles may read any program.
     */
    public function view(User $user, Program $program): bool
    {
        if (! $user->role->isLearnerScoped()) {
            return true;
        }

        return $program->status->isVisibleToLearners();
    }
}

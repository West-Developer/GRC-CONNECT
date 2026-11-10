<?php

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

final class SubjectPolicy
{
    /**
     * Every role may list subjects; Subject::scopeVisibleTo() controls which
     * rows a learner-scoped role actually receives.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * A learner-scoped role may not read a non-learner-visible subject even
     * by direct ID lookup; planning roles may read any subject.
     */
    public function view(User $user, Subject $subject): bool
    {
        if (! $user->role->isLearnerScoped()) {
            return true;
        }

        return $subject->status->isVisibleToLearners();
    }
}

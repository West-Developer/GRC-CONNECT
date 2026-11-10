<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\FacultyAvailability;
use App\Models\User;

/**
 * Own-record authorization, not status-based: a Faculty member may only
 * write their own availability; planning roles may read everyone's (they
 * need the full picture to plan sections) but never write on a professor's
 * behalf. `role:faculty` is the coarse route-level gate for writes; this
 * Policy re-checks ownership as defense in depth.
 */
final class FacultyAvailabilityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FacultyAvailability $availability): bool
    {
        if (! $user->role->isLearnerScoped()) {
            return true;
        }

        return $availability->professor_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Faculty;
    }

    public function update(User $user, FacultyAvailability $availability): bool
    {
        return $user->role === UserRole::Faculty && $availability->professor_id === $user->id;
    }

    public function delete(User $user, FacultyAvailability $availability): bool
    {
        return $this->update($user, $availability);
    }
}

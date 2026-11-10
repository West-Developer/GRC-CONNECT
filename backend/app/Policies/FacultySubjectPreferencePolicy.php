<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\FacultySubjectPreference;
use App\Models\User;

/**
 * Same own-record shape as FacultyAvailabilityPolicy — see its docblock.
 */
final class FacultySubjectPreferencePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FacultySubjectPreference $preference): bool
    {
        if (! $user->role->isLearnerScoped()) {
            return true;
        }

        return $preference->professor_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Faculty;
    }

    public function update(User $user, FacultySubjectPreference $preference): bool
    {
        return $user->role === UserRole::Faculty && $preference->professor_id === $user->id;
    }

    public function delete(User $user, FacultySubjectPreference $preference): bool
    {
        return $this->update($user, $preference);
    }
}

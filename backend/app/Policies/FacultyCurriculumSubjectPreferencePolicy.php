<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\FacultyCurriculumSubjectPreference;
use App\Models\User;

/** Faculty can author only their reusable curriculum preference profile. */
final class FacultyCurriculumSubjectPreferencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Faculty;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Faculty;
    }

    public function update(User $user, FacultyCurriculumSubjectPreference $preference): bool
    {
        return $user->role === UserRole::Faculty && $preference->professor_id === $user->id;
    }

    public function delete(User $user, FacultyCurriculumSubjectPreference $preference): bool
    {
        return $this->update($user, $preference);
    }
}

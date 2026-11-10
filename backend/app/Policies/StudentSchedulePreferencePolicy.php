<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\StudentSchedulePreference;
use App\Models\User;

/**
 * Own-record only, no broader role-based visibility — same shape as
 * StudentProfilePolicy. `viewAny` is the role gate (student only), checked
 * before a row is resolved/built; `view`/`update` re-check ownership on the
 * resolved (possibly not-yet-persisted default) row.
 */
final class StudentSchedulePreferencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    public function view(User $user, StudentSchedulePreference $preference): bool
    {
        return $preference->student->user_id === $user->id;
    }

    public function update(User $user, StudentSchedulePreference $preference): bool
    {
        return $preference->student->user_id === $user->id;
    }
}

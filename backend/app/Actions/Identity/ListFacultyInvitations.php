<?php

namespace App\Actions\Identity;

use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Every Faculty account in one college, newest first — pending invitations
 * and already-activated professors alike, so a Program Chair sees their
 * whole roster's setup status in one place.
 */
final class ListFacultyInvitations
{
    /** @return Collection<int, User> */
    public function handle(CollegeCode $college): Collection
    {
        return User::query()
            ->where('role', UserRole::Faculty)
            ->where('college', $college)
            ->orderByDesc('created_at')
            ->get();
    }
}

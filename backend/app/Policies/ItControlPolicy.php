<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

final class ItControlPolicy
{
    public function viewAccountBrowser(User $user): bool
    {
        return $user->role === UserRole::ItAdmin;
    }

    /**
     * IT Control edits, archives, restores and deletes Student and Faculty accounts and no other role's
     * (ADR 0054): a staff, Super Admin or IT account is never managed from here.
     */
    public function manageAccount(User $user, User $target): bool
    {
        return $user->role === UserRole::ItAdmin
            && in_array($target->role, [UserRole::Student, UserRole::Faculty], true);
    }
}

<?php

namespace App\Policies;

use App\Domain\Identity\UserRole;
use App\Models\User;

final class UserAccountPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    public function invite(User $actor): bool
    {
        return $actor->isSuperAdmin();
    }

    public function changeRole(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && ! $target->isSuperAdmin()
            && $target->role !== UserRole::QueueKiosk;
    }

    public function updateStatus(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && ! $target->isSuperAdmin()
            && $target->role !== UserRole::QueueKiosk;
    }

    public function resendInvitation(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && ! $target->isSuperAdmin()
            && $target->role !== UserRole::QueueKiosk;
    }

    public function resetPassword(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && ! $target->isSuperAdmin()
            && $target->role !== UserRole::QueueKiosk;
    }

    public function revokeSessions(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && ! $target->isSuperAdmin()
            && $target->role !== UserRole::QueueKiosk;
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && ! $target->isSuperAdmin()
            && $target->role !== UserRole::QueueKiosk;
    }
}

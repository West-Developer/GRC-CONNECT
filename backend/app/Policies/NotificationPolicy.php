<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

final class NotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }
}

<?php

namespace App\Actions\Notifications;

use App\Models\Notification;
use App\Models\User;

final readonly class MarkAllNotificationsRead
{
    public function execute(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
            ]);
    }
}


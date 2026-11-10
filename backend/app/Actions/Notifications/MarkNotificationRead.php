<?php

namespace App\Actions\Notifications;

use App\Models\Notification;
use Illuminate\Support\Facades\DB;

final class MarkNotificationRead
{
    public function execute(Notification $notification): Notification
    {
        return DB::transaction(function () use ($notification): Notification {
            $lockedNotification = Notification::query()
                ->whereKey($notification->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedNotification->read_at === null) {
                $lockedNotification->forceFill(['read_at' => now()])->save();
            }

            return $lockedNotification->refresh();
        });
    }
}

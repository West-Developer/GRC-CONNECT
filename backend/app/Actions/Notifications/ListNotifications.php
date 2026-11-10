<?php

namespace App\Actions\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListNotifications
{
    /**
     * @return LengthAwarePaginator<int, Notification>
     */
    public function execute(User $user, bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\ListNotifications;
use App\Actions\Notifications\MarkAllNotificationsRead;
use App\Actions\Notifications\MarkNotificationRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\IndexNotificationRequest;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(
        IndexNotificationRequest $request,
        ListNotifications $listNotifications,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', Notification::class);

        $notifications = $listNotifications->execute(
            $user,
            $request->boolean('unread_only'),
            (int) $request->validated('per_page', 20),
        );

        return $this->cachePrivateResponse(
            NotificationResource::collection($notifications)->response($request),
        );
    }

    /**
     * @throws AuthenticationException
     */
    public function markRead(
        Request $request,
        Notification $notification,
        MarkNotificationRead $markNotificationRead,
    ): JsonResponse {
        $this->authenticatedUser($request);
        $this->authorize('update', $notification);

        $notification = $markNotificationRead->execute($notification);

        return $this->cachePrivateResponse(
            NotificationResource::make($notification)->response($request),
        );
    }

    /**
     * @throws AuthenticationException
     */
    public function markAllRead(
        Request $request,
        MarkAllNotificationsRead $markAllNotificationsRead,
    ): JsonResponse {
        $user = $this->authenticatedUser($request);
        $this->authorize('viewAny', Notification::class);

        $markedCount = $markAllNotificationsRead->execute($user);

        return response()->json([
            'message' => "All {$markedCount} notifications marked as read.",
            'marked_count' => $markedCount,
        ]);
    }

    /**
     * @throws AuthenticationException
     */
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    private function cachePrivateResponse(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

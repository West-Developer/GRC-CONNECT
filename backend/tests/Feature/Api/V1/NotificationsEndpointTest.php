<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Http\Middleware\AssignRequestId;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_list_request_returns_the_standard_unauthenticated_response(): void
    {
        $response = $this
            ->withHeader(AssignRequestId::HEADER, 'anonymous-notifications-list')
            ->getJson('/api/v1/notifications');

        $response
            ->assertUnauthorized()
            ->assertHeader(AssignRequestId::HEADER, 'anonymous-notifications-list')
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.request_id', 'anonymous-notifications-list');
    }

    public function test_anonymous_mark_read_request_returns_the_standard_unauthenticated_response(): void
    {
        $response = $this
            ->withHeader(AssignRequestId::HEADER, 'anonymous-notification-read')
            ->patchJson('/api/v1/notifications/999/read');

        $response
            ->assertUnauthorized()
            ->assertHeader(AssignRequestId::HEADER, 'anonymous-notification-read')
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.request_id', 'anonymous-notification-read');
    }

    public function test_a_disabled_user_is_rejected_from_the_notification_list(): void
    {
        $user = $this->makeUser('disabled-notification-list');
        $token = $this->tokenFor($user);
        $user->update(['status' => UserStatus::Disabled]);

        $this->withToken($token)
            ->getJson('/api/v1/notifications')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_a_disabled_user_is_rejected_from_marking_a_notification_read(): void
    {
        $user = $this->makeUser('disabled-notification-read');
        $notification = $this->makeNotification($user, 'Disabled users cannot read this.');
        $token = $this->tokenFor($user);
        $user->update(['status' => UserStatus::Disabled]);

        $this->withToken($token)
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        self::assertNull($notification->refresh()->read_at);
    }

    public function test_list_returns_only_the_callers_rows_in_deterministic_order_with_the_exact_resource_and_pagination_shape(): void
    {
        $user = $this->makeUser('notification-list-owner');
        $otherUser = $this->makeUser('notification-list-other');
        $token = $this->tokenFor($user);
        $tieTime = CarbonImmutable::parse('2026-07-29 08:00:00', 'UTC');

        for ($index = 1; $index <= 19; $index++) {
            $this->makeNotification(
                $user,
                'Older notification '.$index,
                $tieTime->subMinutes($index),
            );
        }

        $lowerTieId = $this->makeNotification($user, 'Lower tie ID', $tieTime);
        $higherTieId = $this->makeNotification($user, 'Higher tie ID', $tieTime);
        $this->makeNotification($otherUser, 'Another user must never see this.', $tieTime->addHour());

        $response = $this
            ->withToken($token)
            ->withHeader(AssignRequestId::HEADER, 'notification-list-shape')
            ->getJson(route('api.v1.notifications.index', absolute: false));

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader(AssignRequestId::HEADER, 'notification-list-shape')
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.id', $higherTieId->id)
            ->assertJsonPath('data.1.id', $lowerTieId->id)
            ->assertJsonPath('data.0.type', 'notification')
            ->assertJsonPath('data.0.notification_type', 'schedule_published')
            ->assertJsonPath('data.0.message', 'Higher tie ID')
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('data.0.created_at', '2026-07-29T08:00:00Z')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 21)
            ->assertJsonStructure([
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        self::assertSame(
            ['type', 'id', 'notification_type', 'message', 'read_at', 'created_at'],
            array_keys($response->json('data.0')),
        );
        $response->assertJsonMissingPath('data.0.user_id');
        $response->assertDontSee('Another user must never see this.');
    }

    public function test_unread_only_true_excludes_read_notifications(): void
    {
        $user = $this->makeUser('notification-unread-filter');
        $token = $this->tokenFor($user);
        $unread = $this->makeNotification($user, 'Unread notification');
        $this->makeNotification(
            $user,
            'Read notification',
            readAt: CarbonImmutable::parse('2026-07-29 07:00:00', 'UTC'),
        );

        $response = $this->withToken($token)
            ->getJson('/api/v1/notifications?unread_only=true');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unread->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_per_page_accepts_the_inclusive_one_to_one_hundred_bounds(): void
    {
        $user = $this->makeUser('notification-page-bounds');
        $token = $this->tokenFor($user);
        $this->makeNotification($user, 'A notification');

        $this->withToken($token)
            ->getJson('/api/v1/notifications?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        $this->withToken($token)
            ->getJson('/api/v1/notifications?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_pagination_links_preserve_the_unread_filter_and_page_size(): void
    {
        $user = $this->makeUser('notification-page-links');
        $token = $this->tokenFor($user);
        $this->makeNotification($user, 'First unread notification');
        $this->makeNotification($user, 'Second unread notification');
        $this->makeNotification(
            $user,
            'Read notification',
            readAt: CarbonImmutable::parse('2026-07-29 07:00:00', 'UTC'),
        );

        $response = $this->withToken($token)
            ->getJson('/api/v1/notifications?unread_only=true&per_page=1');

        $response->assertOk();
        $nextLink = $response->json('links.next');
        self::assertIsString($nextLink);

        parse_str((string) parse_url($nextLink, PHP_URL_QUERY), $nextQuery);

        self::assertSame([
            'unread_only' => 'true',
            'per_page' => '1',
            'page' => '2',
        ], $nextQuery);
    }

    public function test_invalid_filters_and_pagination_return_the_standard_validation_envelope(): void
    {
        $user = $this->makeUser('notification-invalid-list');
        $token = $this->tokenFor($user);

        foreach ([
            ['query' => 'unread_only=not-a-boolean', 'field' => 'unread_only'],
            ['query' => 'page=0', 'field' => 'page'],
            ['query' => 'per_page=101', 'field' => 'per_page'],
        ] as $case) {
            $response = $this->withToken($token)
                ->getJson('/api/v1/notifications?'.$case['query']);

            $response
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonPath('error.message', 'The submitted data is invalid.')
                ->assertJsonStructure([
                    'error' => ['errors' => [$case['field']]],
                ]);
        }
    }

    public function test_owner_mark_read_sets_one_utc_timestamp_and_retries_preserve_it(): void
    {
        try {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 09:10:11', 'UTC'));

            $user = $this->makeUser('notification-mark-read-owner');
            $notification = $this->makeNotification($user, 'Mark this once.');
            $token = $this->tokenFor($user);

            $firstResponse = $this
                ->withToken($token)
                ->withHeader(AssignRequestId::HEADER, 'notification-first-read')
                ->patchJson(route('api.v1.notifications.read', $notification, false));

            $firstResponse
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader(AssignRequestId::HEADER, 'notification-first-read')
                ->assertJsonPath('data.read_at', '2026-07-29T09:10:11Z')
                ->assertJsonPath('data.message', 'Mark this once.');

            $firstStoredTimestamp = $notification->refresh()->read_at?->format('Y-m-d H:i:s');
            self::assertSame('2026-07-29 09:10:11', $firstStoredTimestamp);

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-29 10:20:30', 'UTC'));

            $retryResponse = $this
                ->withToken($token)
                ->patchJson(route('api.v1.notifications.read', $notification, false));

            $retryResponse
                ->assertOk()
                ->assertJsonPath('data.read_at', '2026-07-29T09:10:11Z');
            self::assertSame(
                $firstStoredTimestamp,
                $notification->refresh()->read_at?->format('Y-m-d H:i:s'),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_non_owner_receives_forbidden_without_notification_content(): void
    {
        $owner = $this->makeUser('notification-forbidden-owner');
        $notification = $this->makeNotification($owner, 'Private notification content.');
        $otherUser = $this->makeUser('notification-forbidden-actor');
        $token = $this->tokenFor($otherUser);

        $response = $this
            ->withToken($token)
            ->withHeader(AssignRequestId::HEADER, 'notification-forbidden')
            ->patchJson("/api/v1/notifications/{$notification->id}/read");

        $response
            ->assertForbidden()
            ->assertHeader(AssignRequestId::HEADER, 'notification-forbidden')
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.request_id', 'notification-forbidden')
            ->assertDontSee('Private notification content.');

        self::assertNull($notification->refresh()->read_at);
    }

    public function test_unknown_notification_id_returns_the_standard_not_found_response(): void
    {
        $user = $this->makeUser('notification-not-found');
        $token = $this->tokenFor($user);

        $response = $this
            ->withToken($token)
            ->withHeader(AssignRequestId::HEADER, 'notification-not-found')
            ->patchJson(route('api.v1.notifications.read', 999999, false));

        $response
            ->assertNotFound()
            ->assertHeader(AssignRequestId::HEADER, 'notification-not-found')
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.request_id', 'notification-not-found');
    }

    public function test_user_can_mark_all_unread_notifications_as_read_in_bulk(): void
    {
        $user = $this->makeUser('bulk-read-user');
        $otherUser = $this->makeUser('bulk-read-other');
        $token = $this->tokenFor($user);

        $notif1 = $this->makeNotification($user, 'Notif 1');
        $notif2 = $this->makeNotification($user, 'Notif 2');
        $notifOther = $this->makeNotification($otherUser, 'Other user notif');

        $response = $this
            ->withToken($token)
            ->patchJson('/api/v1/notifications/read-all');

        $response->assertOk()
            ->assertJsonPath('marked_count', 2);

        self::assertNotNull($notif1->refresh()->read_at);
        self::assertNotNull($notif2->refresh()->read_at);
        self::assertNull($notifOther->refresh()->read_at);
    }

    private function makeUser(string $handle): User
    {
        return User::create([
            'name' => 'Notification User',
            'email' => $handle.'@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('notification-test')->plainTextToken;
    }

    private function makeNotification(
        User $user,
        string $message,
        ?CarbonImmutable $createdAt = null,
        ?CarbonImmutable $readAt = null,
    ): Notification {
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => NotificationType::SchedulePublished,
            'message' => $message,
            'read_at' => $readAt,
        ]);

        if ($createdAt !== null) {
            $notification->forceFill(['created_at' => $createdAt])->save();
        }

        return $notification;
    }
}

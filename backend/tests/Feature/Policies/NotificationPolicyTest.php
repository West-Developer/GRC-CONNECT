<?php

namespace Tests\Feature\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Models\Notification;
use App\Models\User;
use App\Policies\NotificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_authenticated_user_may_view_their_notification_collection(): void
    {
        $user = $this->makeUser('notification-reader');

        self::assertTrue((new NotificationPolicy)->viewAny($user));
    }

    public function test_only_the_notification_owner_may_update_it(): void
    {
        $owner = $this->makeUser('notification-owner');
        $otherUser = $this->makeUser('notification-other');
        $notification = Notification::create([
            'user_id' => $owner->id,
            'type' => NotificationType::SchedulePublished,
            'message' => 'The schedule is now available.',
        ]);

        $policy = new NotificationPolicy;

        self::assertTrue($policy->update($owner, $notification));
        self::assertFalse($policy->update($otherUser, $notification));
    }

    private function makeUser(string $handle): User
    {
        return User::create([
            'name' => 'Notification User',
            'email' => $handle.'@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);
    }
}

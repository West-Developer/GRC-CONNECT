<?php

namespace Tests\Unit\Models;

use App\Domain\Notifications\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

final class NotificationTest extends TestCase
{
    public function test_type_and_read_timestamp_use_their_canonical_casts(): void
    {
        $notification = new Notification;
        $notification->forceFill([
            'type' => 'schedule_published',
            'read_at' => '2026-07-29 08:00:00',
            'created_at' => '2026-07-29 07:00:00',
            'updated_at' => '2026-07-29 08:00:00',
        ]);

        self::assertSame(NotificationType::SchedulePublished, $notification->type);
        self::assertInstanceOf(CarbonImmutable::class, $notification->read_at);
        self::assertInstanceOf(CarbonImmutable::class, $notification->created_at);
        self::assertInstanceOf(CarbonImmutable::class, $notification->updated_at);
    }

    public function test_user_relationship_targets_the_notification_owner(): void
    {
        $relation = (new Notification)->user();

        self::assertInstanceOf(BelongsTo::class, $relation);
        self::assertInstanceOf(User::class, $relation->getRelated());
        self::assertSame('user_id', $relation->getForeignKeyName());
    }

    public function test_user_exposes_its_notifications(): void
    {
        $relation = (new User)->notifications();

        self::assertInstanceOf(HasMany::class, $relation);
        self::assertInstanceOf(Notification::class, $relation->getRelated());
        self::assertSame('user_id', $relation->getForeignKeyName());
    }
}

<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Scheduling\RoomConflictDetector;
use App\Domain\Scheduling\ScheduleDayParser;
use PHPUnit\Framework\TestCase;

final class RoomConflictDetectorTest extends TestCase
{
    public function test_complementary_hyflex_patterns_can_share_a_room_and_time(): void
    {
        $detector = new RoomConflictDetector(new ScheduleDayParser);

        self::assertFalse($detector->hasConflict([
            'schedule_days' => 'Monday',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
            'modality' => 'hyflex_a',
        ], [[
            'schedule_days' => 'Monday',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
            'modality' => 'hyflex_b',
        ]]));
    }

    public function test_f2f_or_the_same_hyflex_pattern_cannot_double_book_a_room(): void
    {
        $detector = new RoomConflictDetector(new ScheduleDayParser);
        $slot = [
            'schedule_days' => 'Monday',
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '10:00:00',
        ];

        self::assertTrue($detector->hasConflict([...$slot, 'modality' => 'f2f'], [[...$slot, 'modality' => 'hyflex_a']]));
        self::assertTrue($detector->hasConflict([...$slot, 'modality' => 'hyflex_b'], [[...$slot, 'modality' => 'hyflex_b']]));
    }

    /**
     * Back-to-back classes share a room legitimately: one ends exactly when
     * the next begins. The interval is half-open, so a touching boundary is
     * never a double-booking.
     */
    public function test_back_to_back_classes_touching_at_the_boundary_are_not_a_conflict(): void
    {
        $detector = new RoomConflictDetector(new ScheduleDayParser);

        self::assertFalse($detector->hasConflict([
            'schedule_days' => 'Monday',
            'starts_at_time' => '13:30:00',
            'ends_at_time' => '16:30:00',
            'modality' => 'f2f',
        ], [[
            'schedule_days' => 'Monday',
            'starts_at_time' => '16:30:00',
            'ends_at_time' => '19:30:00',
            'modality' => 'f2f',
        ]]));
    }

    /** One minute of genuine overlap past the boundary is still a conflict. */
    public function test_one_minute_of_overlap_past_the_boundary_is_a_conflict(): void
    {
        $detector = new RoomConflictDetector(new ScheduleDayParser);

        self::assertTrue($detector->hasConflict([
            'schedule_days' => 'Monday',
            'starts_at_time' => '13:30:00',
            'ends_at_time' => '16:31:00',
            'modality' => 'f2f',
        ], [[
            'schedule_days' => 'Monday',
            'starts_at_time' => '16:30:00',
            'ends_at_time' => '19:30:00',
            'modality' => 'f2f',
        ]]));
    }
}

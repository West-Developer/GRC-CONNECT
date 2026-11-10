<?php

namespace Tests\Unit\Models;

use App\Domain\Scheduling\ScheduleGenerationStatus;
use App\Domain\Scheduling\ScheduleGenerationWarningType;
use App\Models\ScheduleGenerationRun;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class ScheduleGenerationRunTest extends TestCase
{
    public function test_status_warnings_and_timestamps_use_their_canonical_casts(): void
    {
        $run = new ScheduleGenerationRun;
        $run->status = ScheduleGenerationStatus::Queued;
        $run->warnings = [[
            'type' => ScheduleGenerationWarningType::NoRoomAvailable->value,
            'message' => 'No room matched the historical Lab 1 reference.',
            'entity_id' => 8420,
        ]];
        $run->started_at = '2026-08-09 09:00:00';

        self::assertSame(ScheduleGenerationStatus::Queued, $run->status);
        self::assertSame([[
            'type' => 'no_room_available',
            'message' => 'No room matched the historical Lab 1 reference.',
            'entity_id' => 8420,
        ]], $run->warnings);
        self::assertInstanceOf(CarbonImmutable::class, $run->started_at);
    }

    /**
     * Plain array_unique() string-casts each array element to "Array" and
     * silently collapses everything to one entry — SORT_REGULAR is required
     * for correct element-wise comparison of structured warnings.
     */
    public function test_array_unique_with_sort_regular_dedupes_structured_warnings_correctly(): void
    {
        $first = ['type' => 'room_metadata_incomplete', 'message' => 'Room metadata is incomplete for section 8420; review its room manually.', 'entity_id' => 8420];
        $duplicate = ['type' => 'room_metadata_incomplete', 'message' => 'Room metadata is incomplete for section 8420; review its room manually.', 'entity_id' => 8420];
        $different = ['type' => 'no_room_available', 'message' => 'No configured lab room can accommodate section 8421; assign a room manually.', 'entity_id' => 8421];

        $deduped = array_values(array_unique([$first, $duplicate, $different], SORT_REGULAR));

        self::assertCount(2, $deduped);
        self::assertSame([$first, $different], $deduped);
    }
}

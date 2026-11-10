<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Scheduling\ScheduleDayParser;
use App\Domain\Scheduling\SectionConflictDetector;
use PHPUnit\Framework\TestCase;

final class SectionConflictDetectorTest extends TestCase
{
    private SectionConflictDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new SectionConflictDetector(new ScheduleDayParser);
    }

    public function test_no_existing_sections_means_no_conflict(): void
    {
        $proposed = ['schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00'];

        self::assertFalse($this->detector->hasConflict($proposed, []));
    }

    public function test_overlapping_time_on_a_shared_day_is_a_conflict(): void
    {
        $proposed = ['schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00'];
        $existing = [['schedule_days' => 'MWF', 'starts_at_time' => '08:30:00', 'ends_at_time' => '09:30:00']];

        self::assertTrue($this->detector->hasConflict($proposed, $existing));
    }

    public function test_identical_time_on_a_shared_day_is_a_conflict(): void
    {
        $proposed = ['schedule_days' => 'TTh', 'starts_at_time' => '10:00:00', 'ends_at_time' => '11:30:00'];
        $existing = [['schedule_days' => 'TTh', 'starts_at_time' => '10:00:00', 'ends_at_time' => '11:30:00']];

        self::assertTrue($this->detector->hasConflict($proposed, $existing));
    }

    public function test_adjacent_but_non_overlapping_times_are_not_a_conflict(): void
    {
        $proposed = ['schedule_days' => 'MWF', 'starts_at_time' => '09:00:00', 'ends_at_time' => '10:00:00'];
        $existing = [['schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00']];

        self::assertFalse($this->detector->hasConflict($proposed, $existing));
    }

    public function test_overlapping_time_on_a_different_day_is_not_a_conflict(): void
    {
        $proposed = ['schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00'];
        $existing = [['schedule_days' => 'TTh', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00']];

        self::assertFalse($this->detector->hasConflict($proposed, $existing));
    }

    public function test_partially_shared_days_still_conflict_on_the_shared_day(): void
    {
        // MWF vs TTh only share... nothing; use MW vs W to share Wednesday.
        $proposed = ['schedule_days' => 'MW', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00'];
        $existing = [['schedule_days' => 'WF', 'starts_at_time' => '08:30:00', 'ends_at_time' => '09:30:00']];

        self::assertTrue($this->detector->hasConflict($proposed, $existing));
    }

    public function test_a_slot_missing_schedule_information_never_conflicts(): void
    {
        $proposed = ['schedule_days' => null, 'starts_at_time' => null, 'ends_at_time' => null];
        $existing = [['schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00']];

        self::assertFalse($this->detector->hasConflict($proposed, $existing));
    }

    public function test_an_existing_slot_missing_schedule_information_is_skipped_not_fatal(): void
    {
        $proposed = ['schedule_days' => 'MWF', 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00'];
        $existing = [['schedule_days' => null, 'starts_at_time' => null, 'ends_at_time' => null]];

        self::assertFalse($this->detector->hasConflict($proposed, $existing));
    }
}

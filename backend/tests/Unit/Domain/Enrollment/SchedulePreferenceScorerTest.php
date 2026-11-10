<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\SchedulePreferenceScorer;
use App\Models\StudentSchedulePreference;
use PHPUnit\Framework\TestCase;

/**
 * Covers `App\Domain\Enrollment\SchedulePreferenceScorer` — the pure,
 * read-only rating of a candidate enrollment option's sections against a
 * student's saved `StudentSchedulePreference`.
 */
final class SchedulePreferenceScorerTest extends TestCase
{
    public function test_it_scores_a_block_that_matches_every_preference_highest(): void
    {
        $preference = new StudentSchedulePreference([
            'preferred_days' => [1, 2, 3], 'preferred_time_block' => 'morning',
            'max_days_on_campus' => 3, 'avoid_early_first_class' => true,
        ]);

        $good = $this->sections([[1, '09:00:00'], [2, '10:00:00'], [3, '09:00:00']]);
        $bad = $this->sections([[4, '17:00:00'], [5, '07:00:00'], [6, '18:00:00']]);

        $this->assertGreaterThan(
            SchedulePreferenceScorer::score($preference, $bad)['score'],
            SchedulePreferenceScorer::score($preference, $good)['score'],
        );
        $this->assertContains('No class before 8:00 AM', SchedulePreferenceScorer::score($preference, $good)['reasons']);
    }

    public function test_it_returns_a_null_score_when_no_preference_is_set(): void
    {
        $this->assertNull(SchedulePreferenceScorer::score(null, $this->sections([[1, '09:00:00']]))['score']);
    }

    /**
     * Builds section-shaped rows in the same plain-array shape
     * `SectionConflictDetector::hasConflict()` already uses for day/time
     * comparisons (`schedule_days`, `starts_at_time`) — one row per
     * single-day `[dayOfWeek, startTime]` pair.
     *
     * @param  list<array{0: int, 1: string}>  $entries
     * @return list<array{schedule_days: string, starts_at_time: string, modality: ?string}>
     */
    private function sections(array $entries): array
    {
        return array_map(
            fn (array $entry): array => [
                'schedule_days' => $this->dayToken($entry[0]),
                'starts_at_time' => $entry[1],
                'modality' => null,
            ],
            $entries,
        );
    }

    /** Matches `ScheduleDayParser::TOKENS`' ISO-8601 numbering (1 = Monday … 7 = Sunday). */
    private function dayToken(int $dayOfWeek): string
    {
        return match ($dayOfWeek) {
            1 => 'M',
            2 => 'T',
            3 => 'W',
            4 => 'Th',
            5 => 'F',
            6 => 'Sat',
            7 => 'Sun',
            default => throw new \InvalidArgumentException("Unsupported day of week: {$dayOfWeek}"),
        };
    }
}

<?php

namespace App\Domain\Scheduling;

/**
 * Parses `sections.schedule_days` shorthand (e.g. "MWF", "TTh", "Sat") into
 * ISO-8601 day-of-week integers (1 = Monday … 7 = Sunday), matching
 * FacultyAvailability::$day_of_week's numbering so the two can eventually be
 * compared on the same scale.
 *
 * This shorthand is not a PRD-specified vocabulary — it is the convention
 * already used by `SectionSeeder`. Parsing is greedy and stops at the first
 * unrecognized token rather than guessing, so a malformed string yields a
 * partial (possibly empty) day list instead of a wrong one.
 */
final class ScheduleDayParser
{
    /**
     * Longest tokens first so "Th"/"Sat"/"Sun" are not swallowed by the
     * single-letter "T"/"S" checks.
     *
     * @var array<string, int>
     */
    private const TOKENS = [
        'TH' => 4,
        'SAT' => 6,
        'SUN' => 7,
        'M' => 1,
        'T' => 2,
        'W' => 3,
        'F' => 5,
    ];

    /**
     * @return list<int>
     */
    public function parse(?string $scheduleDays): array
    {
        if ($scheduleDays === null) {
            return [];
        }

        $days = [];
        $remaining = strtoupper($scheduleDays);

        while ($remaining !== '') {
            $matchedToken = null;

            foreach (self::TOKENS as $token => $dayOfWeek) {
                if (str_starts_with($remaining, $token)) {
                    $matchedToken = $token;
                    $days[] = $dayOfWeek;
                    break;
                }
            }

            if ($matchedToken === null) {
                break;
            }

            $remaining = substr($remaining, strlen($matchedToken));
        }

        return array_values(array_unique($days));
    }
}

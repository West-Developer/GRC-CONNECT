<?php

namespace App\Domain\Analytics;

use InvalidArgumentException;

/**
 * Resolves the approved historical cohort reference for section demand.
 */
final class HistoricalCohortResolver
{
    public function resolve(string $schoolYear, string $semester, int $yearLevel): HistoricalCohortReference
    {
        if (! preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $matches)) {
            throw new InvalidArgumentException('School year must use YYYY-YYYY format.');
        }

        if (! in_array($semester, ['1st', '2nd'], true)) {
            throw new InvalidArgumentException('Semester must be 1st or 2nd.');
        }

        if ($yearLevel < 1 || $yearLevel > 4) {
            throw new InvalidArgumentException('Year level must be from 1 through 4.');
        }

        if ($semester === '2nd') {
            return new HistoricalCohortReference($schoolYear, '1st', $yearLevel);
        }

        $previousSchoolYear = ((int) $matches[1] - 1).'-'.((int) $matches[2] - 1);

        if ($yearLevel === 1) {
            return new HistoricalCohortReference($previousSchoolYear, '1st', 1);
        }

        return new HistoricalCohortReference($previousSchoolYear, '2nd', $yearLevel - 1);
    }
}

<?php

namespace App\Domain\Analytics;

/**
 * One (school_year, semester) point in a college-scoped enrollment
 * year-over-year series. `schoolYear`/`semester` are plain identifier
 * columns on `academic_terms` (not a flagged-provisional enum), already used
 * for joins elsewhere — see HistoricalCohortResolver.
 */
final readonly class AnalyticsYearOverYearPoint
{
    public function __construct(
        public string $schoolYear,
        public string $semester,
        public int $enrolleeCount,
        public int $stoppedCount = 0,
        public float $attritionRate = 0.0,
    ) {}
}

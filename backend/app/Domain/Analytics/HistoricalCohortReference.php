<?php

namespace App\Domain\Analytics;

final readonly class HistoricalCohortReference
{
    public function __construct(
        public string $schoolYear,
        public string $semester,
        public int $yearLevel,
    ) {}
}

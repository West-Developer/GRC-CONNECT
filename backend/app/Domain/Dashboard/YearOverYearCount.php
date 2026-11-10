<?php

namespace App\Domain\Dashboard;

final readonly class YearOverYearCount
{
    public function __construct(
        public string $schoolYear,
        public int $enrollmentCount,
    ) {}
}

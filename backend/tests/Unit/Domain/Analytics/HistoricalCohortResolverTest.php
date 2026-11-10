<?php

namespace Tests\Unit\Domain\Analytics;

use App\Domain\Analytics\HistoricalCohortResolver;
use PHPUnit\Framework\TestCase;

final class HistoricalCohortResolverTest extends TestCase
{
    public function test_second_semester_uses_the_same_year_levels_first_semester(): void
    {
        $reference = (new HistoricalCohortResolver)->resolve('2027-2028', '2nd', 3);

        self::assertSame('2027-2028', $reference->schoolYear);
        self::assertSame('1st', $reference->semester);
        self::assertSame(3, $reference->yearLevel);
    }

    public function test_first_semester_advances_the_prior_second_semester_cohort(): void
    {
        $reference = (new HistoricalCohortResolver)->resolve('2027-2028', '1st', 3);

        self::assertSame('2026-2027', $reference->schoolYear);
        self::assertSame('2nd', $reference->semester);
        self::assertSame(2, $reference->yearLevel);
    }

    public function test_incoming_first_year_first_semester_uses_last_years_matching_cohort(): void
    {
        $reference = (new HistoricalCohortResolver)->resolve('2027-2028', '1st', 1);

        self::assertSame('2026-2027', $reference->schoolYear);
        self::assertSame('1st', $reference->semester);
        self::assertSame(1, $reference->yearLevel);
    }
}

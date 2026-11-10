<?php

namespace Tests\Unit\Models;

use App\Models\SectionDemandObservation;
use PHPUnit\Framework\TestCase;

final class SectionDemandObservationTest extends TestCase
{
    public function test_historical_demand_counts_are_cast_to_integers(): void
    {
        $observation = new SectionDemandObservation([
            'year_level' => '3',
            'cohort_size' => '87',
            'enrolled_count' => '91',
            'section_count' => '3',
            'offered_capacity' => '120',
        ]);

        self::assertSame(3, $observation->year_level);
        self::assertSame(87, $observation->cohort_size);
        self::assertSame(91, $observation->enrolled_count);
        self::assertSame(3, $observation->section_count);
        self::assertSame(120, $observation->offered_capacity);
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\Assessment;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AssessmentTest extends TestCase
{
    public function test_assessed_at_is_cast_to_carbon_immutable(): void
    {
        $assessment = new Assessment;
        $assessment->forceFill([
            'enrollment_id' => 1,
            'total_amount' => '5775.00',
            'currency' => 'PHP',
            'assessed_at' => '2026-08-05 09:00:00',
        ]);

        self::assertInstanceOf(CarbonImmutable::class, $assessment->assessed_at);
    }

    public function test_total_amount_deliberately_stays_a_raw_string_not_a_float(): void
    {
        $assessment = new Assessment;
        $assessment->forceFill([
            'enrollment_id' => 1,
            'total_amount' => '5775.00',
            'currency' => 'PHP',
            'assessed_at' => '2026-08-05 09:00:00',
        ]);

        self::assertIsString($assessment->total_amount);
        self::assertSame('5775.00', $assessment->total_amount);
    }
}

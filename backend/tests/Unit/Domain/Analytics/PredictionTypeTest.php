<?php

namespace Tests\Unit\Domain\Analytics;

use App\Domain\Analytics\PredictionType;
use PHPUnit\Framework\TestCase;

final class PredictionTypeTest extends TestCase
{
    public function test_values_are_the_approved_prediction_types(): void
    {
        self::assertSame(
            ['section_demand', 'attrition'],
            array_column(PredictionType::cases(), 'value'),
        );
    }
}

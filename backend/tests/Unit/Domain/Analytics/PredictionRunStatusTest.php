<?php

namespace Tests\Unit\Domain\Analytics;

use App\Domain\Analytics\PredictionRunStatus;
use PHPUnit\Framework\TestCase;

final class PredictionRunStatusTest extends TestCase
{
    public function test_values_are_the_prd_prediction_lifecycle(): void
    {
        self::assertSame(
            ['queued', 'running', 'succeeded', 'failed'],
            array_column(PredictionRunStatus::cases(), 'value'),
        );
    }
}

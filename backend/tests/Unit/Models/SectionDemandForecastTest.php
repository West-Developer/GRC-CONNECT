<?php

namespace Tests\Unit\Models;

use App\Models\AcademicTerm;
use App\Models\PredictionRun;
use App\Models\SectionDemandForecast;
use App\Models\Subject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tests\TestCase;

final class SectionDemandForecastTest extends TestCase
{
    public function test_fixed_point_forecast_values_and_timestamps_use_their_canonical_casts(): void
    {
        $forecast = new SectionDemandForecast;
        $forecast->forceFill([
            'predicted_demand' => '42.5',
            'confidence_lower' => '36',
            'confidence_upper' => '49',
            'suggested_section_count' => '2',
            'created_at' => '2026-07-29 08:00:00',
            'updated_at' => '2026-07-29 08:05:00',
        ]);

        self::assertSame('42.50', $forecast->predicted_demand);
        self::assertSame('36.00', $forecast->confidence_lower);
        self::assertSame('49.00', $forecast->confidence_upper);
        self::assertSame(2, $forecast->suggested_section_count);
        self::assertInstanceOf(CarbonImmutable::class, $forecast->created_at);
        self::assertInstanceOf(CarbonImmutable::class, $forecast->updated_at);
    }

    public function test_prediction_run_relationship_targets_the_generating_run(): void
    {
        $relation = (new SectionDemandForecast)->predictionRun();

        self::assertInstanceOf(BelongsTo::class, $relation);
        self::assertInstanceOf(PredictionRun::class, $relation->getRelated());
        self::assertSame('prediction_run_id', $relation->getForeignKeyName());
    }

    public function test_academic_term_relationship_targets_the_forecast_term(): void
    {
        $relation = (new SectionDemandForecast)->academicTerm();

        self::assertInstanceOf(BelongsTo::class, $relation);
        self::assertInstanceOf(AcademicTerm::class, $relation->getRelated());
        self::assertSame('academic_term_id', $relation->getForeignKeyName());
    }

    public function test_subject_relationship_targets_the_forecast_subject(): void
    {
        $relation = (new SectionDemandForecast)->subject();

        self::assertInstanceOf(BelongsTo::class, $relation);
        self::assertInstanceOf(Subject::class, $relation->getRelated());
        self::assertSame('subject_id', $relation->getForeignKeyName());
    }
}

<?php

namespace Tests\Unit\Models;

use App\Domain\Analytics\PredictionRunStatus;
use App\Domain\Analytics\PredictionType;
use App\Models\AcademicTerm;
use App\Models\AttritionPrediction;
use App\Models\PredictionRun;
use App\Models\SectionDemandForecast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

final class PredictionRunTest extends TestCase
{
    public function test_type_status_metrics_and_timestamps_use_their_canonical_casts(): void
    {
        $predictionRun = new PredictionRun;
        $predictionRun->forceFill([
            'type' => 'section_demand',
            'status' => 'succeeded',
            'metrics' => ['mae' => 1.25],
            'started_at' => '2026-07-29 08:00:00',
            'completed_at' => '2026-07-29 08:05:00',
            'created_at' => '2026-07-29 07:55:00',
            'updated_at' => '2026-07-29 08:05:00',
        ]);

        self::assertSame(PredictionType::SectionDemand, $predictionRun->type);
        self::assertSame(PredictionRunStatus::Succeeded, $predictionRun->status);
        self::assertSame(['mae' => 1.25], $predictionRun->metrics);
        self::assertInstanceOf(CarbonImmutable::class, $predictionRun->started_at);
        self::assertInstanceOf(CarbonImmutable::class, $predictionRun->completed_at);
        self::assertInstanceOf(CarbonImmutable::class, $predictionRun->created_at);
        self::assertInstanceOf(CarbonImmutable::class, $predictionRun->updated_at);
    }

    public function test_academic_term_relationship_targets_the_run_context(): void
    {
        $relation = (new PredictionRun)->academicTerm();

        self::assertInstanceOf(BelongsTo::class, $relation);
        self::assertInstanceOf(AcademicTerm::class, $relation->getRelated());
        self::assertSame('academic_term_id', $relation->getForeignKeyName());
    }

    public function test_section_demand_forecasts_relationship_targets_run_results(): void
    {
        $relation = (new PredictionRun)->sectionDemandForecasts();

        self::assertInstanceOf(HasMany::class, $relation);
        self::assertInstanceOf(SectionDemandForecast::class, $relation->getRelated());
        self::assertSame('prediction_run_id', $relation->getForeignKeyName());
    }

    public function test_attrition_predictions_relationship_targets_run_results(): void
    {
        $relation = (new PredictionRun)->attritionPredictions();

        self::assertInstanceOf(HasMany::class, $relation);
        self::assertInstanceOf(AttritionPrediction::class, $relation->getRelated());
        self::assertSame('prediction_run_id', $relation->getForeignKeyName());
    }
}

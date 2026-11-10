<?php

namespace Tests\Unit\Models;

use App\Models\AttritionPrediction;
use App\Models\PredictionRun;
use App\Models\StudentProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tests\TestCase;

final class AttritionPredictionTest extends TestCase
{
    public function test_fixed_point_risk_explanations_and_timestamps_use_their_canonical_casts(): void
    {
        $prediction = new AttritionPrediction;
        $prediction->forceFill([
            'risk_probability' => '0.8',
            'explanations' => ['attendance trend'],
            'created_at' => '2026-07-29 08:00:00',
            'updated_at' => '2026-07-29 08:05:00',
        ]);

        self::assertSame('0.8000', $prediction->risk_probability);
        self::assertSame(['attendance trend'], $prediction->explanations);
        self::assertInstanceOf(CarbonImmutable::class, $prediction->created_at);
        self::assertInstanceOf(CarbonImmutable::class, $prediction->updated_at);
    }

    public function test_prediction_run_relationship_targets_the_generating_run(): void
    {
        $relation = (new AttritionPrediction)->predictionRun();

        self::assertInstanceOf(BelongsTo::class, $relation);
        self::assertInstanceOf(PredictionRun::class, $relation->getRelated());
        self::assertSame('prediction_run_id', $relation->getForeignKeyName());
    }

    public function test_student_relationship_targets_the_predicted_student(): void
    {
        $relation = (new AttritionPrediction)->student();

        self::assertInstanceOf(BelongsTo::class, $relation);
        self::assertInstanceOf(StudentProfile::class, $relation->getRelated());
        self::assertSame('student_id', $relation->getForeignKeyName());
    }
}

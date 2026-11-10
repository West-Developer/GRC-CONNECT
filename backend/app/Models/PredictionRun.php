<?php

namespace App\Models;

use App\Domain\Analytics\PredictionRunStatus;
use App\Domain\Analytics\PredictionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property PredictionType $type
 * @property ?int $academic_term_id
 * @property string $model_version
 * @property string $feature_schema_version
 * @property PredictionRunStatus $status
 * @property ?array<string, mixed> $metrics
 * @property ?string $error_summary
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $completed_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read ?AcademicTerm $academicTerm
 * @property-read Collection<int, SectionDemandForecast> $sectionDemandForecasts
 * @property-read Collection<int, AttritionPrediction> $attritionPredictions
 */
final class PredictionRun extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'type',
        'academic_term_id',
        'model_version',
        'feature_schema_version',
        'status',
        'metrics',
        'error_summary',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PredictionType::class,
            'status' => PredictionRunStatus::class,
            'metrics' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AcademicTerm, $this>
     */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /**
     * @return HasMany<SectionDemandForecast, $this>
     */
    public function sectionDemandForecasts(): HasMany
    {
        return $this->hasMany(SectionDemandForecast::class);
    }

    /**
     * @return HasMany<AttritionPrediction, $this>
     */
    public function attritionPredictions(): HasMany
    {
        return $this->hasMany(AttritionPrediction::class);
    }
}

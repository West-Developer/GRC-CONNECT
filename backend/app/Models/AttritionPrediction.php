<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $prediction_run_id
 * @property int $student_id
 * @property string $risk_probability
 * @property string $risk_band
 * @property ?array<int, string> $explanations
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read PredictionRun $predictionRun
 * @property-read StudentProfile $student
 */
final class AttritionPrediction extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'prediction_run_id',
        'student_id',
        'risk_probability',
        'risk_band',
        'explanations',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'risk_probability' => 'decimal:4',
            'explanations' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PredictionRun, $this>
     */
    public function predictionRun(): BelongsTo
    {
        return $this->belongsTo(PredictionRun::class);
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }
}

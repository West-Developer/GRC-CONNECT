<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $prediction_run_id
 * @property int $academic_term_id
 * @property int $subject_id
 * @property string $predicted_demand
 * @property int $suggested_section_count
 * @property ?string $confidence_lower
 * @property ?string $confidence_upper
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read PredictionRun $predictionRun
 * @property-read AcademicTerm $academicTerm
 * @property-read Subject $subject
 */
final class SectionDemandForecast extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'prediction_run_id',
        'academic_term_id',
        'program_id',
        'curriculum_id',
        'subject_id',
        'year_level',
        'historical_school_year',
        'historical_semester',
        'historical_year_level',
        'predicted_demand',
        'suggested_section_count',
        'confidence_lower',
        'confidence_upper',
        'rationale',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'predicted_demand' => 'decimal:2',
            'confidence_lower' => 'decimal:2',
            'confidence_upper' => 'decimal:2',
            'suggested_section_count' => 'integer',
            'year_level' => 'integer',
            'historical_year_level' => 'integer',
            'rationale' => 'array',
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
     * @return BelongsTo<AcademicTerm, $this>
     */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<Curriculum, $this> */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }
}

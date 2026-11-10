<?php

namespace App\Models;

use App\Domain\Scheduling\ScheduleGenerationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $academic_term_id
 * @property ?int $prediction_run_id
 * @property string $college
 * @property int $initiated_by
 * @property ScheduleGenerationStatus $status
 * @property ?array<int, string> $warnings
 * @property ?string $error_summary
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $completed_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class ScheduleGenerationRun extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'academic_term_id',
        'prediction_run_id',
        'college',
        'initiated_by',
        'status',
        'warnings',
        'error_summary',
        'started_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ScheduleGenerationStatus::class,
            'warnings' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AcademicTerm, $this> */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /** @return BelongsTo<PredictionRun, $this> */
    public function predictionRun(): BelongsTo
    {
        return $this->belongsTo(PredictionRun::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}

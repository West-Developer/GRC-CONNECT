<?php

namespace App\Models;

use App\Domain\Faculty\SpecializationProficiency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $schedule_generation_run_id
 * @property int $section_id
 * @property ?int $recommended_professor_id
 * @property ?int $preference_rank
 * @property ?SpecializationProficiency $specialization_match
 * @property bool $availability_match
 * @property bool $conflict_free
 * @property ?array<int, string> $rationale
 */
final class FacultyAssignmentRecommendation extends Model
{
    protected $fillable = [
        'schedule_generation_run_id', 'section_id', 'recommended_professor_id',
        'preference_rank', 'specialization_match', 'availability_match', 'conflict_free', 'rationale',
    ];

    protected function casts(): array
    {
        return [
            'preference_rank' => 'integer',
            'specialization_match' => SpecializationProficiency::class,
            'availability_match' => 'boolean',
            'conflict_free' => 'boolean',
            'rationale' => 'array',
        ];
    }

    /** @return BelongsTo<ScheduleGenerationRun, $this> */
    public function scheduleGenerationRun(): BelongsTo
    {
        return $this->belongsTo(ScheduleGenerationRun::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recommendedProfessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommended_professor_id');
    }
}

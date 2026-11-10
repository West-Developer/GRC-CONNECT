<?php

namespace App\Models;

use App\Domain\Enrollment\PreferredTimeBlock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $student_id
 * @property ?array<int, int> $preferred_days
 * @property PreferredTimeBlock $preferred_time_block
 * @property ?string $preferred_modality
 * @property ?int $max_days_on_campus
 * @property bool $avoid_early_first_class
 * @property ?string $notes
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read StudentProfile $student
 */
final class StudentSchedulePreference extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'preferred_days',
        'preferred_time_block',
        'preferred_modality',
        'max_days_on_campus',
        'avoid_early_first_class',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'preferred_days' => 'array',
            'preferred_time_block' => PreferredTimeBlock::class,
            'max_days_on_campus' => 'integer',
            'avoid_early_first_class' => 'boolean',
        ];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }
}

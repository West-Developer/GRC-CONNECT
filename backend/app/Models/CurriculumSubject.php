<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject's placement within one curriculum: which year/semester it is
 * taken in, and (via SubjectPrerequisite) what it requires beforehand.
 *
 * @property int $id
 * @property int $curriculum_id
 * @property int $subject_id
 * @property int $year_level
 * @property string $semester
 * @property bool $is_required
 * @property ?string $reference_day
 * @property ?string $reference_start_time
 * @property ?string $reference_end_time
 * @property ?string $reference_room
 * @property ?string $reference_modality
 * @property ?string $reference_professor_name
 * @property ?string $reference_sched_id
 * @property ?string $reference_notes
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Curriculum $curriculum
 * @property-read Subject $subject
 * @property-read Collection<int, SubjectPrerequisite> $prerequisites
 */
final class CurriculumSubject extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'curriculum_id',
        'subject_id',
        'year_level',
        'semester',
        'is_required',
        'reference_day',
        'reference_start_time',
        'reference_end_time',
        'reference_room',
        'reference_modality',
        'reference_professor_name',
        'reference_sched_id',
        'reference_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Curriculum, $this>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return HasMany<SubjectPrerequisite, $this>
     */
    public function prerequisites(): HasMany
    {
        return $this->hasMany(SubjectPrerequisite::class);
    }
}

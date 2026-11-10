<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subject's planned min/max section capacity and manually-entered
 * recommended section count for one academic term, within one curriculum.
 *
 * @property int $id
 * @property int $academic_term_id
 * @property int $curriculum_id
 * @property int $subject_id
 * @property int $year_level
 * @property string $semester
 * @property int $min_section_capacity
 * @property int $max_section_capacity
 * @property int $recommended_sections
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read AcademicTerm $academicTerm
 * @property-read Curriculum $curriculum
 * @property-read Subject $subject
 */
final class SubjectOffering extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'academic_term_id',
        'curriculum_id',
        'subject_id',
        'year_level',
        'semester',
        'min_section_capacity',
        'max_section_capacity',
        'recommended_sections',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'min_section_capacity' => 'integer',
            'max_section_capacity' => 'integer',
            'recommended_sections' => 'integer',
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
}

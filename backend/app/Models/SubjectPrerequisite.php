<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $curriculum_subject_id
 * @property int $prerequisite_subject_id
 * @property string $minimum_grade
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read CurriculumSubject $curriculumSubject
 * @property-read Subject $prerequisiteSubject
 */
final class SubjectPrerequisite extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'curriculum_subject_id',
        'prerequisite_subject_id',
        'minimum_grade',
    ];

    /**
     * @return BelongsTo<CurriculumSubject, $this>
     */
    public function curriculumSubject(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubject::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function prerequisiteSubject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'prerequisite_subject_id');
    }
}

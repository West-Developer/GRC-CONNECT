<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One explicit old-to-new subject equivalency inside one curriculum-version
 * transition. Both unique keys enforce the approved one-to-one rule.
 *
 * @property int $id
 * @property int $source_curriculum_id
 * @property int $target_curriculum_id
 * @property int $source_subject_id
 * @property int $target_subject_id
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Curriculum $sourceCurriculum
 * @property-read Curriculum $targetCurriculum
 * @property-read Subject $sourceSubject
 * @property-read Subject $targetSubject
 */
final class CurriculumSubjectEquivalency extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'source_curriculum_id',
        'target_curriculum_id',
        'source_subject_id',
        'target_subject_id',
    ];

    /** @return BelongsTo<Curriculum, $this> */
    public function sourceCurriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class, 'source_curriculum_id');
    }

    /** @return BelongsTo<Curriculum, $this> */
    public function targetCurriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class, 'target_curriculum_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function sourceSubject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'source_subject_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function targetSubject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'target_subject_id');
    }
}

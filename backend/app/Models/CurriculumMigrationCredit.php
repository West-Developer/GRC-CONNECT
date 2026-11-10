<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $id @property int $curriculum_migration_id @property int $curriculum_subject_equivalency_id @property int $source_academic_grade_id @property int $target_subject_id */
final class CurriculumMigrationCredit extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'curriculum_migration_id', 'curriculum_subject_equivalency_id',
        'source_academic_grade_id', 'target_subject_id',
    ];

    /** @return BelongsTo<CurriculumMigration, $this> */
    public function migration(): BelongsTo
    {
        return $this->belongsTo(CurriculumMigration::class, 'curriculum_migration_id');
    }

    /** @return BelongsTo<CurriculumSubjectEquivalency, $this> */
    public function equivalency(): BelongsTo
    {
        return $this->belongsTo(CurriculumSubjectEquivalency::class, 'curriculum_subject_equivalency_id');
    }

    /** @return BelongsTo<AcademicGrade, $this> */
    public function sourceAcademicGrade(): BelongsTo
    {
        return $this->belongsTo(AcademicGrade::class, 'source_academic_grade_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function targetSubject(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'target_subject_id');
    }
}

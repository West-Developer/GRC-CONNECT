<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable Program-Chair decision moving one student between the exact
 * old and new curricula configured by an equivalency map.
 *
 * @property int $id
 * @property int $student_id
 * @property int $source_curriculum_id
 * @property int $target_curriculum_id
 * @property int $processed_by
 * @property CarbonImmutable $migrated_at
 * @property-read StudentProfile $student
 * @property-read Curriculum $sourceCurriculum
 * @property-read Curriculum $targetCurriculum
 * @property-read User $processor
 * @property-read Collection<int, CurriculumMigrationCredit> $credits
 */
final class CurriculumMigration extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'student_id', 'source_curriculum_id', 'target_curriculum_id',
        'processed_by', 'migrated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['migrated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<StudentProfile, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

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

    /** @return BelongsTo<User, $this> */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** @return HasMany<CurriculumMigrationCredit, $this> */
    public function credits(): HasMany
    {
        return $this->hasMany(CurriculumMigrationCredit::class);
    }
}

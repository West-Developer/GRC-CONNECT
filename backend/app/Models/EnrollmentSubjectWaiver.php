<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Registrar Head exception: this student may take this subject in this
 * term although a prerequisite is not met (ADR 0031). Active while
 * `revoked_at` is null.
 *
 * @property int $id
 * @property int $student_id
 * @property int $subject_id
 * @property int $academic_term_id
 * @property string $reason
 * @property int $granted_by
 * @property CarbonImmutable $granted_at
 * @property ?int $revoked_by
 * @property ?CarbonImmutable $revoked_at
 * @property-read Subject $subject
 * @property-read StudentProfile $student
 */
final class EnrollmentSubjectWaiver extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'subject_id',
        'academic_term_id',
        'reason',
        'granted_by',
        'granted_at',
        'revoked_by',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<EnrollmentSubjectWaiver>  $query
     * @return Builder<EnrollmentSubjectWaiver>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}

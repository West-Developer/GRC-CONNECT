<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $professor_id
 * @property int $academic_term_id
 * @property int $subject_id
 * @property int $rank
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read User $professor
 * @property-read AcademicTerm $academicTerm
 * @property-read Subject $subject
 */
final class FacultySubjectPreference extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'professor_id',
        'academic_term_id',
        'subject_id',
        'rank',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function professor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professor_id');
    }

    /**
     * @return BelongsTo<AcademicTerm, $this>
     */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Own-record visibility, not status-based — see
     * FacultyAvailability::scopeVisibleTo() for the same rationale.
     *
     * @param  Builder<FacultySubjectPreference>  $query
     * @return Builder<FacultySubjectPreference>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->role->isLearnerScoped()) {
            return $query;
        }

        return $query->where('professor_id', $user->id);
    }
}

<?php

namespace App\Models;

use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $enrollment_id
 * @property int $section_id
 * @property EnrollmentSubjectStatus $status
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Enrollment $enrollment
 * @property-read Section $section
 */
final class EnrollmentSubject extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'section_id',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EnrollmentSubjectStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * Backs the class roster read (PRD §3.2 "View assigned teaching
     * schedules and class rosters"). `EnrollmentSubjectPolicy::viewAny`
     * already restricts callers to Faculty, Registrar Staff, and Registrar
     * Head before this scope ever runs, so only Faculty needs narrowing
     * here — to their own sections, the same `professor_id` check
     * `Section::scopeVisibleTo` uses.
     *
     * @param  Builder<EnrollmentSubject>  $query
     * @return Builder<EnrollmentSubject>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::Faculty) {
            return $query->whereHas('section', fn ($sectionQuery) => $sectionQuery->where('professor_id', $user->id));
        }

        return $query;
    }
}

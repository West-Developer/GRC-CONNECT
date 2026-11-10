<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring weekly availability window for one professor.
 * `day_of_week` uses ISO-8601 numbering (1 = Monday … 6 = Saturday).
 *
 * @property int $id
 * @property int $professor_id
 * @property int $day_of_week
 * @property string $starts_at_time
 * @property string $ends_at_time
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read User $professor
 */
final class FacultyAvailability extends Model
{
    /**
     * Laravel would otherwise pluralize this to `faculty_availabilities`
     * incorrectly via its inflector; the table name is pinned explicitly so
     * the model and migration cannot drift.
     *
     * @var string
     */
    protected $table = 'faculty_availabilities';

    /** @var list<string> */
    protected $fillable = [
        'professor_id',
        'day_of_week',
        'starts_at_time',
        'ends_at_time',
        'origin',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
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
     * Own-record visibility, not status-based: a learner-scoped role (in
     * practice, Faculty) sees only their own declared availability; planning
     * roles need the full picture to plan sections, so they see everyone's.
     *
     * @param  Builder<FacultyAvailability>  $query
     * @return Builder<FacultyAvailability>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->role->isLearnerScoped()) {
            return $query;
        }

        return $query->where('professor_id', $user->id);
    }
}

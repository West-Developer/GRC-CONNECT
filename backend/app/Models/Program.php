<?php

namespace App\Models;

use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property ?CollegeCode $college
 * @property string $name
 * @property ProgramStatus $status
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 */
final class Program extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'college',
        'name',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'college' => CollegeCode::class,
            'status' => ProgramStatus::class,
        ];
    }

    /**
     * Restricts the result set for learner-scoped roles to learner-visible
     * programs. Program Chairs receive only their assigned college's
     * programs; without an assigned college, they receive none. Other
     * planning roles (see UserRole::isLearnerScoped()) are passed through
     * unfiltered — they see the full catalog.
     *
     * @param  Builder<Program>  $query
     * @return Builder<Program>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::ProgramChair) {
            if ($user->college === null) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('college', $user->college->value);
        }

        if (! $user->role->isLearnerScoped()) {
            return $query;
        }

        $visibleValues = array_values(array_map(
            fn (ProgramStatus $status): string => $status->value,
            array_filter(
                ProgramStatus::cases(),
                fn (ProgramStatus $status): bool => $status->isVisibleToLearners(),
            ),
        ));

        return $query->whereIn('status', $visibleValues);
    }
}

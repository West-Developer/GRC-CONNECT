<?php

namespace App\Models;

use App\Domain\Enrollment\WithdrawalStatus;
use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $enrollment_id
 * @property string $reason
 * @property WithdrawalStatus $status
 * @property ?int $processed_by
 * @property ?CarbonImmutable $processed_at
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Enrollment $enrollment
 * @property-read ?User $processor
 */
final class WithdrawalRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'reason',
        'status',
        'processed_by',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WithdrawalStatus::class,
            'processed_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * PRD §3.8 assigns processing withdrawal requests to Registrar Staff
     * specifically; the Registrar Head also reads every request (the same
     * "keeper of the official record" visibility `Enrollment`/`AcademicGrade`
     * already grant it), but does not process one here — see
     * `WithdrawalRequestPolicy`'s docblock for that literal-reading choice.
     *
     * @param  Builder<WithdrawalRequest>  $query
     * @return Builder<WithdrawalRequest>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (in_array($user->role, [UserRole::RegistrarStaff, UserRole::RegistrarHead], true)) {
            return $query;
        }

        return $query->whereHas(
            'enrollment.student',
            fn ($studentQuery) => $studentQuery->where('user_id', $user->id),
        );
    }
}

<?php

namespace App\Models;

use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Transferee's or Returnee's "I have no subject to credit", waiting for (or confirmed by) the Program Chair.
 *
 * @property int $id
 * @property int $student_id
 * @property CarbonImmutable $declared_at
 * @property ?CarbonImmutable $confirmed_at
 * @property ?int $confirmed_by_user_id
 * @property-read StudentProfile $student
 */
final class NoCreditDeclaration extends Model
{
    protected $table = 'student_no_credit_declarations';

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'declared_at',
        'confirmed_at',
        'confirmed_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'declared_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * A Program Chair sees the declarations of students in their own college (every college when they have none
     * assigned, the same fallback `TransfereeCredit::scopeVisibleTo` uses); nobody else lists them.
     *
     * @param  Builder<NoCreditDeclaration>  $query
     * @return Builder<NoCreditDeclaration>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role !== UserRole::ProgramChair) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->college === null) {
            return $query;
        }

        return $query->whereHas(
            'student.program',
            fn (Builder $programQuery) => $programQuery->where('college', $user->college->value),
        );
    }

    /**
     * @return BelongsTo<StudentProfile, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}

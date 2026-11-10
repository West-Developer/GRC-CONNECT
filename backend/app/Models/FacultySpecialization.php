<?php

namespace App\Models;

use App\Domain\Faculty\FacultySpecializationStatus;
use App\Domain\Faculty\SpecializationProficiency;
use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $professor_id
 * @property int $subject_id
 * @property SpecializationProficiency $proficiency
 * @property string $source
 * @property FacultySpecializationStatus $status
 * @property ?int $decided_by
 * @property ?CarbonImmutable $decided_at
 * @property ?string $decision_reason
 * @property ?string $notes
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read User $professor
 * @property-read Subject $subject
 */
final class FacultySpecialization extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'professor_id',
        'subject_id',
        'proficiency',
        'source',
        'notes',
        'status',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'proficiency' => SpecializationProficiency::class,
            'status' => FacultySpecializationStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function professor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professor_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Faculty members see only their own profile. A Program Chair sees only
     * their own college's professors. Other planning roles (e.g. Registrar
     * Head) need the full cross-college teaching-capability picture.
     *
     * @param  Builder<FacultySpecialization>  $query
     * @return Builder<FacultySpecialization>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->role->isLearnerScoped()) {
            return $query->where('professor_id', $user->id);
        }

        if ($user->role === UserRole::ProgramChair) {
            return $query->whereHas(
                'professor',
                fn (Builder $professors) => $professors->where('college', $user->college?->value),
            );
        }

        return $query;
    }
}

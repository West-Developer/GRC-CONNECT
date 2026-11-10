<?php

namespace App\Models;

use App\Domain\Enrollment\EnrollmentChangeRequestStatus;
use App\Domain\Enrollment\EnrollmentChangeRequestType;
use App\Domain\Identity\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $enrollment_id
 * @property EnrollmentChangeRequestType $type
 * @property int $subject_id
 * @property ?int $from_section_id
 * @property ?int $to_section_id
 * @property string $reason
 * @property EnrollmentChangeRequestStatus $status
 * @property ?int $decided_by
 * @property ?CarbonImmutable $decided_at
 * @property ?string $decision_reason
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Enrollment $enrollment
 * @property-read Subject $subject
 * @property-read ?Section $fromSection
 * @property-read ?Section $toSection
 * @property-read ?User $decider
 */
final class EnrollmentChangeRequest extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'type',
        'subject_id',
        'from_section_id',
        'to_section_id',
        'reason',
        'status',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EnrollmentChangeRequestType::class,
            'status' => EnrollmentChangeRequestStatus::class,
            'decided_at' => 'immutable_datetime',
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
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function fromSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'from_section_id');
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function toSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'to_section_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Student own; Registrar Head decides so reads everything; Registrar
     * Staff reads everything too (explicit user requirement: "viewable by
     * Registrar Staff") but never decides — see
     * `EnrollmentChangeRequestPolicy`.
     *
     * @param  Builder<EnrollmentChangeRequest>  $query
     * @return Builder<EnrollmentChangeRequest>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (in_array($user->role, [UserRole::RegistrarHead, UserRole::RegistrarStaff], true)) {
            return $query;
        }

        return $query->whereHas(
            'enrollment.student',
            fn ($studentQuery) => $studentQuery->where('user_id', $user->id),
        );
    }
}

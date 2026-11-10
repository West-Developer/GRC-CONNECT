<?php

namespace App\Models;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Scheduling\ScheduleProposalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $academic_term_id
 * @property int $submitted_by
 * @property ScheduleProposalStatus $status
 * @property ?int $decided_by
 * @property ?CarbonImmutable $decided_at
 * @property ?string $decision_reason
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read AcademicTerm $academicTerm
 * @property-read User $submitter
 * @property-read ?User $decider
 * @property-read Collection<int, AuditLog> $decisionAudits
 */
final class ScheduleProposal extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'academic_term_id',
        'college',
        'section_plan_id',
        'submitted_by',
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
            'status' => ScheduleProposalStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AcademicTerm, $this>
     */
    public function academicTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Immutable Dean and Executive Director decisions for the Program Chair
     * timeline. Creation/publication/closure events are intentionally omitted.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function decisionAudits(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'auditable_id')
            ->where('auditable_type', AuditableType::SCHEDULE_PROPOSAL)
            ->whereIn('action', [
                AuditAction::SCHEDULE_PROPOSAL_DEAN_APPROVED,
                AuditAction::SCHEDULE_PROPOSAL_DEAN_RETURNED,
                AuditAction::SCHEDULE_PROPOSAL_EXECUTIVE_APPROVED,
                AuditAction::SCHEDULE_PROPOSAL_EXECUTIVE_RETURNED,
            ])
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Restricts the result set for learner-scoped roles to
     * published/closed proposals. Planning roles see every proposal
     * regardless of status. Same pattern as Curriculum/Section::scopeVisibleTo().
     *
     * @param  Builder<ScheduleProposal>  $query
     * @return Builder<ScheduleProposal>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->role->isLearnerScoped()) {
            return $query;
        }

        $visibleValues = array_values(array_map(
            fn (ScheduleProposalStatus $status): string => $status->value,
            array_filter(
                ScheduleProposalStatus::cases(),
                fn (ScheduleProposalStatus $status): bool => $status->isVisibleToLearners(),
            ),
        ));

        return $query->whereIn('status', $visibleValues);
    }
}

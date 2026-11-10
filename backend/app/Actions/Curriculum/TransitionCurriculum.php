<?php

namespace App\Actions\Curriculum;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Curriculum\CurriculumTransitionRules;
use App\Domain\Identity\UserRole;
use App\Models\Curriculum;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\CurriculumAuditSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies one transition in the curriculum approval chain
 * (Draft -> PendingDeanReview -> PendingExecutiveReview -> Active, with a
 * required-reason return to Draft at either checkpoint — see
 * `CurriculumTransitionRules`) and records who decided it and when.
 * Mirrors `App\Actions\Scheduling\TransitionScheduleProposal`'s shape.
 */
final class TransitionCurriculum
{
    /**
     * @var array<string, string>
     */
    private const AUDIT_ACTION = [
        'submit' => AuditAction::CURRICULUM_SUBMITTED,
        'dean_approve' => AuditAction::CURRICULUM_DEAN_APPROVED,
        'dean_return' => AuditAction::CURRICULUM_DEAN_RETURNED,
        'executive_approve' => AuditAction::CURRICULUM_EXECUTIVE_APPROVED,
        'executive_return' => AuditAction::CURRICULUM_EXECUTIVE_RETURNED,
    ];

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly CurriculumAuditSnapshot $snapshot,
        private readonly NotifyCurriculumTransition $notifyCurriculumTransition,
    ) {}

    public function execute(
        Curriculum $curriculum,
        string $action,
        User $actingUser,
        ?string $reason,
        AuditRequestContext $context,
    ): Curriculum {
        CurriculumTransitionRules::requiredStatus($action);

        if (
            CurriculumTransitionRules::isReturn($action)
            && ($reason === null || trim($reason) === '')
        ) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when returning a curriculum to draft.',
            ]);
        }

        return DB::transaction(function () use ($curriculum, $action, $actingUser, $reason, $context): Curriculum {
            $lockedCurriculum = Curriculum::query()
                ->whereKey($curriculum->id)
                ->lockForUpdate()
                ->firstOrFail();
            $requiredStatus = CurriculumTransitionRules::requiredStatus($action);

            if ($lockedCurriculum->status !== $requiredStatus) {
                throw ValidationException::withMessages([
                    'action' => "This action requires the curriculum to currently be '{$requiredStatus->value}'; ".
                        "it is currently '{$lockedCurriculum->status->value}'.",
                ]);
            }

            $beforeValues = $this->snapshot->capture($lockedCurriculum);
            $isReturn = CurriculumTransitionRules::isReturn($action);
            $decisionReason = $isReturn ? $reason : null;
            // The submitter to notify on every later checkpoint is whoever
            // applied `submit` — capture it before this action's `decided_by`
            // overwrites the column, unless this call *is* the submit.
            $submitterId = $action === 'submit' ? $actingUser->id : $lockedCurriculum->decided_by;

            $lockedCurriculum->update([
                'status' => CurriculumTransitionRules::targetStatus($action),
                'decided_by' => $submitterId,
                'decided_at' => now(),
                'last_decision_reason' => $decisionReason ?? $lockedCurriculum->last_decision_reason,
            ]);
            $lockedCurriculum->refresh();

            $afterValues = $this->snapshot->capture($lockedCurriculum);

            $this->auditRecorder->record(
                $actingUser,
                self::AUDIT_ACTION[$action],
                AuditableType::CURRICULUM,
                $lockedCurriculum->id,
                $beforeValues,
                $afterValues,
                $decisionReason,
                $context,
            );

            match ($action) {
                'submit' => $this->notifyCurriculumTransition->submittedForDean($lockedCurriculum),
                'dean_approve' => $this->notifyCurriculumTransition->deanApproved($lockedCurriculum),
                'executive_approve' => $this->notifyCurriculumTransition->executiveApproved($lockedCurriculum),
                'dean_return' => $this->notifyCurriculumTransition->returned($lockedCurriculum, UserRole::Dean, (string) $decisionReason),
                'executive_return' => $this->notifyCurriculumTransition->returned($lockedCurriculum, UserRole::ExecutiveDirector, (string) $decisionReason),
                default => null,
            };

            return $lockedCurriculum->load([
                'subjectPlacements.subject',
                'subjectPlacements.prerequisites.prerequisiteSubject',
                'equivalencySourceCurriculum',
                'targetEquivalencies.sourceSubject',
            ]);
        });
    }
}

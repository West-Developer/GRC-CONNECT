<?php

namespace App\Actions\Scheduling;

use App\Actions\Enrollment\NotifyAwaitingFreshmen;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermCollegeWorkflowStage;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\ScheduleProposalTransitionRules;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermCollegeWorkflow;
use App\Models\AcademicTermSectionPlan;
use App\Models\Notification;
use App\Models\ScheduleProposal;
use App\Models\Section;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies one transition in the schedule lifecycle
 * (draft → dean_approved → published → closed — see
 * `ScheduleProposalStatus`'s docblock for the PRD §4.1 amendment this
 * reflects) and records who decided it and when.
 *
 * `publish` is the only action with a side effect beyond the proposal row
 * itself: it transitions every `planned` section in the proposal's term
 * individually to `published`, in the same transaction — this is what exposes
 * the term's schedule to students and professors, since there is no direct
 * foreign key between `schedule_proposals` and `sections` (see ADR 0011).
 */
final class TransitionScheduleProposal
{
    /**
     * @var array<string, string>
     */
    private const AUDIT_ACTION = [
        'dean_approve' => AuditAction::SCHEDULE_PROPOSAL_DEAN_APPROVED,
        'dean_return' => AuditAction::SCHEDULE_PROPOSAL_DEAN_RETURNED,
        'executive_return' => AuditAction::SCHEDULE_PROPOSAL_EXECUTIVE_RETURNED,
        'publish' => AuditAction::SCHEDULE_PROPOSAL_PUBLISHED,
        'close' => AuditAction::SCHEDULE_PROPOSAL_CLOSED,
    ];

    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly NotifyScheduleTransition $notifyScheduleTransition,
        private readonly NotifyAwaitingFreshmen $notifyAwaitingFreshmen,
    ) {}

    public function execute(
        ScheduleProposal $proposal,
        string $action,
        User $actingUser,
        ?string $reason,
        AuditRequestContext $context,
    ): ScheduleProposal {
        ScheduleProposalTransitionRules::requiredStatus($action);

        if (
            ScheduleProposalTransitionRules::isReturn($action)
            && ($reason === null || trim($reason) === '')
        ) {
            throw ValidationException::withMessages([
                'decision_reason' => 'A decision reason is required when returning a proposal to draft.',
            ]);
        }

        return DB::transaction(function () use ($proposal, $action, $actingUser, $reason, $context): ScheduleProposal {
            $lockedProposal = ScheduleProposal::query()
                ->whereKey($proposal->id)
                ->lockForUpdate()
                ->firstOrFail();
            $requiredStatus = ScheduleProposalTransitionRules::requiredStatus($action);

            if ($lockedProposal->status !== $requiredStatus) {
                throw ValidationException::withMessages([
                    'action' => "This action requires the proposal to currently be '{$requiredStatus->value}'; ".
                        "it is currently '{$lockedProposal->status->value}'.",
                ]);
            }

            $beforeValues = self::snapshot($lockedProposal);
            $decisionReason = ScheduleProposalTransitionRules::isReturn($action) ? $reason : null;

            $lockedProposal->update([
                'status' => ScheduleProposalTransitionRules::targetStatus($action),
                'decided_by' => $actingUser->id,
                'decided_at' => now(),
                'decision_reason' => $decisionReason,
            ]);
            $lockedProposal->refresh();

            if (ScheduleProposalTransitionRules::isReturn($action) && $lockedProposal->college !== null) {
                AcademicTermSectionPlan::query()
                    ->where('academic_term_id', $lockedProposal->academic_term_id)
                    ->where('college', $lockedProposal->college)
                    ->update([
                        'status' => SectionPlanStatus::Draft,
                        'submitted_by' => null,
                        'submitted_at' => null,
                    ]);

                AcademicTermCollegeWorkflow::query()
                    ->where('academic_term_id', $lockedProposal->academic_term_id)
                    ->where('college', $lockedProposal->college)
                    ->update([
                        'stage' => AcademicTermCollegeWorkflowStage::SchedulePreparation,
                        'schedule_submitted_by' => null,
                        'schedule_submitted_at' => null,
                    ]);
            }

            $publishedSectionIds = [];
            $publicationRecipientIds = [$lockedProposal->submitted_by];

            if ($action === 'publish') {
                $sections = Section::query()
                    ->where('academic_term_id', $lockedProposal->academic_term_id)
                    ->when($lockedProposal->college !== null, fn ($query) => $query->whereHas('sectionPlan', fn ($plans) => $plans->where('college', $lockedProposal->college)))
                    ->whereIn('status', [SectionStatus::Planned->value, SectionStatus::Draft->value])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($sections as $section) {
                    $priorStatus = $section->status->value;
                    $section->update(['status' => SectionStatus::Published]);
                    $section->refresh();
                    $publishedSectionIds[] = $section->id;

                    if ($section->professor_id !== null) {
                        $publicationRecipientIds[] = $section->professor_id;
                    }

                    $this->auditRecorder->record(
                        $actingUser,
                        AuditAction::SECTION_PUBLISHED,
                        AuditableType::SECTION,
                        $section->id,
                        ['status' => $priorStatus],
                        ['status' => SectionStatus::Published->value],
                        null,
                        $context,
                    );
                }
            }

            $afterValues = self::snapshot($lockedProposal);

            if ($action === 'publish') {
                $afterValues['published_section_ids'] = $publishedSectionIds;
            }

            $this->auditRecorder->record(
                $actingUser,
                self::AUDIT_ACTION[$action],
                AuditableType::SCHEDULE_PROPOSAL,
                $lockedProposal->id,
                $beforeValues,
                $afterValues,
                $decisionReason,
                $context,
            );

            if ($action === 'publish') {
                $term = AcademicTerm::query()->findOrFail($lockedProposal->academic_term_id);
                $message = "Schedule for {$term->school_year} {$term->semester} has been published.";
                $publicationRecipientIds = array_values(array_unique($publicationRecipientIds));
                sort($publicationRecipientIds);

                foreach ($publicationRecipientIds as $recipientId) {
                    Notification::create([
                        'user_id' => $recipientId,
                        'type' => NotificationType::SchedulePublished,
                        'message' => $message,
                    ]);
                }

                // ADR 0043: freshmen who paid while every section was full learn a section opened.
                $this->notifyAwaitingFreshmen->forSections($publishedSectionIds);
            }

            $reviewerNotificationTerm = null;
            $fetchReviewerNotificationTerm = function () use (&$reviewerNotificationTerm, $lockedProposal): AcademicTerm {
                return $reviewerNotificationTerm ??= AcademicTerm::query()->findOrFail($lockedProposal->academic_term_id);
            };

            match ($action) {
                'dean_approve' => $this->notifyScheduleTransition->deanApproved($lockedProposal, $fetchReviewerNotificationTerm()),
                'dean_return' => $this->notifyScheduleTransition->returned($lockedProposal, $fetchReviewerNotificationTerm(), UserRole::Dean, (string) $decisionReason),
                'executive_return' => $this->notifyScheduleTransition->returned($lockedProposal, $fetchReviewerNotificationTerm(), UserRole::ExecutiveDirector, (string) $decisionReason),
                default => null,
            };

            return $lockedProposal->refresh();
        });
    }

    /**
     * @return array{
     *     academic_term_id: int,
     *     submitted_by: int,
     *     status: string,
     *     decided_by: ?int,
     *     decided_at: ?string,
     *     decision_reason: ?string
     * }
     */
    private static function snapshot(ScheduleProposal $proposal): array
    {
        return [
            'academic_term_id' => $proposal->academic_term_id,
            'submitted_by' => $proposal->submitted_by,
            'status' => $proposal->status->value,
            'decided_by' => $proposal->decided_by,
            'decided_at' => $proposal->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'decision_reason' => $proposal->decision_reason,
        ];
    }
}

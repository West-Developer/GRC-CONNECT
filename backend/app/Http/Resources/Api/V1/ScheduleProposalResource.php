<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\SectionPlanStatus;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Models\AcademicTermSectionPlan;
use App\Models\AuditLog;
use App\Models\ScheduleProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ScheduleProposal $resource
 */
final class ScheduleProposalResource extends JsonResource
{
    /** @var array<string, array{action: string, label: string}> */
    private const DECISION_PRESENTATION = [
        AuditAction::SCHEDULE_PROPOSAL_DEAN_APPROVED => ['action' => 'dean_approve', 'label' => 'Approved by Dean'],
        AuditAction::SCHEDULE_PROPOSAL_DEAN_RETURNED => ['action' => 'dean_return', 'label' => 'Returned by Dean'],
        AuditAction::SCHEDULE_PROPOSAL_EXECUTIVE_APPROVED => ['action' => 'executive_approve', 'label' => 'Approved by Executive Director'],
        AuditAction::SCHEDULE_PROPOSAL_EXECUTIVE_RETURNED => ['action' => 'executive_return', 'label' => 'Returned by Executive Director'],
    ];

    /**
     * Exact key set. No attribute is passed through implicitly.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     academic_term_id: int,
     *     submitted_by: int,
     *     is_submitted: bool,
     *     is_returned: bool,
     *     returned_by_role: ?string,
     *     status: string,
     *     status_label: string,
     *     decided_by: ?int,
     *     decided_at: ?string,
     *     decision_reason: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing([
            'academicTerm:id,school_year,semester',
            'submitter:id,name',
            'decider:id,name',
            'decisionAudits.actor:id,name,role',
        ]);
        $college = $this->resource->college;
        $isSubmitted = $college === null || AcademicTermSectionPlan::query()
            ->where('academic_term_id', $this->resource->academic_term_id)
            ->where('college', $college)
            ->where('status', SectionPlanStatus::Submitted)
            ->exists();

        // There is no dedicated "returned" status — `dean_return` and
        // `executive_return` both resolve to `draft` (see
        // `ScheduleProposalTransitionRules`). This is the one place that
        // derives "returned" from status + is_submitted + decision_reason,
        // so every consumer (Program Chair workspace, reviewer dialogs) reads
        // the same authoritative flag instead of re-deriving it themselves.
        $isReturned = $this->resource->status === ScheduleProposalStatus::Draft
            && ! $isSubmitted
            && $this->resource->decision_reason !== null;
        /** @var ?AuditLog $lastReturnedAudit */
        $lastReturnedAudit = $isReturned
            ? $this->resource->decisionAudits
                ->reverse()
                ->first(fn (AuditLog $audit): bool => in_array($audit->action, [
                    AuditAction::SCHEDULE_PROPOSAL_DEAN_RETURNED,
                    AuditAction::SCHEDULE_PROPOSAL_EXECUTIVE_RETURNED,
                ], true))
            : null;

        return [
            'type' => 'schedule_proposal',
            'id' => $this->resource->id,
            'academic_term_id' => $this->resource->academic_term_id,
            'college' => $this->resource->college,
            'college_label' => $college === null ? null : CollegeCode::tryFrom($college)?->label(),
            'academic_term_label' => $this->resource->academicTerm->school_year.' · '.$this->resource->academicTerm->semester,
            'section_plan_id' => $this->resource->section_plan_id,
            'submitted_by' => $this->resource->submitted_by,
            'submitted_by_name' => $this->resource->submitter->name,
            'is_submitted' => $isSubmitted,
            'is_returned' => $isReturned,
            'returned_by_role' => $lastReturnedAudit?->effectiveActorRole()->value,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'decided_by' => $this->resource->decided_by,
            'decided_by_name' => $this->resource->decider?->name,
            'decided_at' => $this->resource->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'decision_reason' => $this->resource->decision_reason,
            'decision_history' => $this->resource->decisionAudits->map(function ($audit): array {
                $presentation = self::DECISION_PRESENTATION[$audit->action];

                return [
                    'action' => $presentation['action'],
                    'action_label' => $presentation['label'],
                    'actor_name' => $audit->actor->name,
                    'actor_role' => $audit->effectiveActorRole()->value,
                    'decided_at' => $audit->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'notes' => $audit->reason,
                ];
            })->values()->all(),
        ];
    }
}

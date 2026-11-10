<?php

namespace App\Actions\Scheduling;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Scheduling\ScheduleTransitionNotificationPlan;
use App\Models\AcademicTerm;
use App\Models\ScheduleProposal;
use App\Models\User;
use App\Support\Notifications\NotificationRecorder;
use InvalidArgumentException;

/**
 * Resolves `ScheduleTransitionNotificationPlan`'s pure recipient/message
 * rules against the database and writes the resulting notification rows.
 * Recipients are resolved by role rather than a fixed reviewer id because
 * neither the Dean nor the Executive Director is scoped to a single college
 * in this system (see `ScheduleProposalPolicy` and
 * `ScheduleProposalController::index`) — every active Dean/Executive
 * Director is a legitimate reviewer for any college.
 */
final class NotifyScheduleTransition
{
    public function __construct(private readonly NotificationRecorder $notificationRecorder) {}

    public function submittedForDean(ScheduleProposal $proposal, AcademicTerm $term): void
    {
        $this->apply(ScheduleTransitionNotificationPlan::SUBMITTED_FOR_DEAN, $proposal, $term, null);
    }

    public function deanApproved(ScheduleProposal $proposal, AcademicTerm $term): void
    {
        $this->apply('dean_approve', $proposal, $term, null);
    }

    public function returned(
        ScheduleProposal $proposal,
        AcademicTerm $term,
        UserRole $reviewerRole,
        string $reason,
    ): void {
        $action = match ($reviewerRole) {
            UserRole::Dean => 'dean_return',
            UserRole::ExecutiveDirector => 'executive_return',
            default => throw new InvalidArgumentException('Only the Dean or Executive Director can return a schedule proposal.'),
        };

        $this->apply($action, $proposal, $term, $reason);
    }

    private function apply(string $action, ScheduleProposal $proposal, AcademicTerm $term, ?string $reason): void
    {
        $plan = ScheduleTransitionNotificationPlan::forAction(
            $action,
            $this->collegeAndTermLabel($proposal, $term),
            $reason,
        );

        foreach ($plan as $item) {
            $recipientIds = $item['audience'] === 'submitter'
                ? $this->submitterIds($proposal)
                : $this->activeUserIdsForRole($item['audience']);

            $this->notificationRecorder->recordMany($recipientIds, $item['type'], $item['message']);
        }
    }

    /**
     * @return list<int>
     */
    private function submitterIds(ScheduleProposal $proposal): array
    {
        return [$proposal->submitted_by];
    }

    private function collegeAndTermLabel(ScheduleProposal $proposal, AcademicTerm $term): string
    {
        $collegeLabel = $proposal->college === null
            ? 'The schedule'
            : (CollegeCode::tryFrom($proposal->college)?->label() ?? $proposal->college)."'s schedule";

        return "{$collegeLabel} for {$term->school_year} {$term->semester}";
    }

    /**
     * @return list<int>
     */
    private function activeUserIdsForRole(UserRole $role): array
    {
        $ids = User::query()
            ->where('role', $role->value)
            ->where('status', UserStatus::Active->value)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_values($ids);
    }
}

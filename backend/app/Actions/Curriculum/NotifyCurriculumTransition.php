<?php

namespace App\Actions\Curriculum;

use App\Domain\Curriculum\CurriculumTransitionNotificationPlan;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\Curriculum;
use App\Models\User;
use App\Support\Notifications\NotificationRecorder;
use InvalidArgumentException;

/**
 * Resolves `CurriculumTransitionNotificationPlan`'s pure recipient/message
 * rules against the database and writes the resulting notification rows —
 * mirrors `App\Actions\Scheduling\NotifyScheduleTransition`.
 */
final class NotifyCurriculumTransition
{
    public function __construct(private readonly NotificationRecorder $notificationRecorder) {}

    public function submittedForDean(Curriculum $curriculum): void
    {
        $this->apply(CurriculumTransitionNotificationPlan::SUBMITTED_FOR_DEAN, $curriculum, null);
    }

    public function deanApproved(Curriculum $curriculum): void
    {
        $this->apply('dean_approve', $curriculum, null);
    }

    public function executiveApproved(Curriculum $curriculum): void
    {
        $this->apply('executive_approve', $curriculum, null);
    }

    public function returned(Curriculum $curriculum, UserRole $reviewerRole, string $reason): void
    {
        $action = match ($reviewerRole) {
            UserRole::Dean => 'dean_return',
            UserRole::ExecutiveDirector => 'executive_return',
            default => throw new InvalidArgumentException('Only the Dean or Executive Director can return a curriculum.'),
        };

        $this->apply($action, $curriculum, $reason);
    }

    private function apply(string $action, Curriculum $curriculum, ?string $reason): void
    {
        $plan = CurriculumTransitionNotificationPlan::forAction(
            $action,
            "{$curriculum->name}",
            $reason,
        );

        foreach ($plan as $item) {
            $recipientIds = $item['audience'] === 'submitter'
                ? $this->submitterIds($curriculum)
                : $this->activeUserIdsForRole($item['audience']);

            $this->notificationRecorder->recordMany($recipientIds, $item['type'], $item['message']);
        }
    }

    /**
     * @return list<int>
     */
    private function submitterIds(Curriculum $curriculum): array
    {
        return $curriculum->decided_by === null ? [] : [$curriculum->decided_by];
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

<?php

namespace App\Domain\Scheduling;

use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use InvalidArgumentException;

/**
 * Pure recipient/message rules for schedule-proposal notifications — no
 * database access, so this stays unit-testable even while the local test
 * database is missing migrations `App\Actions\Scheduling\NotifyScheduleTransition`
 * needs to actually resolve role membership (see PROGRESS.md's `grc_test`
 * CREATE-privilege note). Recipients are described by audience (`submitter`
 * or a role, meaning "every active user with that role") rather than
 * concrete user IDs; resolving a role to IDs is the caller's job.
 *
 * @phpstan-type NotificationPlanItem array{audience: 'submitter'|UserRole, type: NotificationType, message: string}
 */
final class ScheduleTransitionNotificationPlan
{
    public const SUBMITTED_FOR_DEAN = 'submitted_for_dean';

    /**
     * @return list<array{audience: 'submitter'|UserRole, type: NotificationType, message: string}>
     */
    public static function forAction(string $action, string $collegeAndTermLabel, ?string $reason): array
    {
        return match ($action) {
            self::SUBMITTED_FOR_DEAN => [
                [
                    'audience' => UserRole::Dean,
                    'type' => NotificationType::ScheduleSubmittedForDean,
                    'message' => "{$collegeAndTermLabel} was submitted for your review.",
                ],
            ],
            'dean_approve' => [
                [
                    'audience' => 'submitter',
                    'type' => NotificationType::ScheduleDeanApproved,
                    'message' => "{$collegeAndTermLabel} was approved by the Dean.",
                ],
                [
                    'audience' => UserRole::ExecutiveDirector,
                    'type' => NotificationType::ScheduleDeanApproved,
                    'message' => "{$collegeAndTermLabel} was approved by the Dean and is ready for your review — publish it or return it with notes.",
                ],
            ],
            'dean_return' => [
                [
                    'audience' => 'submitter',
                    'type' => NotificationType::ScheduleReturned,
                    'message' => "Dean returned {$collegeAndTermLabel}. Reason: {$reason}",
                ],
            ],
            'executive_return' => [
                [
                    'audience' => 'submitter',
                    'type' => NotificationType::ScheduleReturned,
                    'message' => "Executive Director returned {$collegeAndTermLabel}. Reason: {$reason}",
                ],
            ],
            default => throw new InvalidArgumentException("Unknown schedule notification action: {$action}"),
        };
    }
}

<?php

namespace App\Domain\Curriculum;

use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use InvalidArgumentException;

/**
 * Pure recipient/message rules for curriculum-transition notifications —
 * mirrors `App\Domain\Scheduling\ScheduleTransitionNotificationPlan`
 * exactly. Recipients are described by audience (`submitter` or a role,
 * meaning "every active user with that role") rather than concrete user
 * IDs; resolving a role to IDs is the caller's job (`NotifyCurriculumTransition`).
 *
 * @phpstan-type NotificationPlanItem array{audience: 'submitter'|UserRole, type: NotificationType, message: string}
 */
final class CurriculumTransitionNotificationPlan
{
    public const SUBMITTED_FOR_DEAN = 'submitted_for_dean';

    /**
     * @return list<array{audience: 'submitter'|UserRole, type: NotificationType, message: string}>
     */
    public static function forAction(string $action, string $curriculumLabel, ?string $reason): array
    {
        return match ($action) {
            self::SUBMITTED_FOR_DEAN => [
                [
                    'audience' => UserRole::Dean,
                    'type' => NotificationType::CurriculumSubmittedForDean,
                    'message' => "{$curriculumLabel} was submitted for your review.",
                ],
            ],
            'dean_approve' => [
                [
                    'audience' => 'submitter',
                    'type' => NotificationType::CurriculumDeanApproved,
                    'message' => "{$curriculumLabel} was approved by the Dean.",
                ],
                [
                    'audience' => UserRole::ExecutiveDirector,
                    'type' => NotificationType::CurriculumDeanApproved,
                    'message' => "{$curriculumLabel} was approved by the Dean and is ready for your review.",
                ],
            ],
            'executive_approve' => [
                [
                    'audience' => 'submitter',
                    'type' => NotificationType::CurriculumExecutiveApproved,
                    'message' => "{$curriculumLabel} was approved and is now Active.",
                ],
            ],
            'dean_return' => [
                [
                    'audience' => 'submitter',
                    'type' => NotificationType::CurriculumReturned,
                    'message' => "Dean returned {$curriculumLabel}. Reason: {$reason}",
                ],
            ],
            'executive_return' => [
                [
                    'audience' => 'submitter',
                    'type' => NotificationType::CurriculumReturned,
                    'message' => "Executive Director returned {$curriculumLabel}. Reason: {$reason}",
                ],
            ],
            default => throw new InvalidArgumentException("Unknown curriculum notification action: {$action}"),
        };
    }
}

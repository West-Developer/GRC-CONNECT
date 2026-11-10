<?php

namespace Tests\Unit\Domain\Curriculum;

use App\Domain\Curriculum\CurriculumTransitionNotificationPlan;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CurriculumTransitionNotificationPlanTest extends TestCase
{
    public function test_submitted_for_dean_notifies_every_dean(): void
    {
        $plan = CurriculumTransitionNotificationPlan::forAction('submitted_for_dean', 'BSCS Curriculum 2026-2027', null);

        self::assertSame([
            ['audience' => UserRole::Dean, 'type' => NotificationType::CurriculumSubmittedForDean, 'message' => 'BSCS Curriculum 2026-2027 was submitted for your review.'],
        ], $plan);
    }

    public function test_dean_approve_notifies_the_submitter_and_every_executive_director(): void
    {
        $plan = CurriculumTransitionNotificationPlan::forAction('dean_approve', 'BSCS Curriculum 2026-2027', null);

        self::assertSame([
            ['audience' => 'submitter', 'type' => NotificationType::CurriculumDeanApproved, 'message' => 'BSCS Curriculum 2026-2027 was approved by the Dean.'],
            ['audience' => UserRole::ExecutiveDirector, 'type' => NotificationType::CurriculumDeanApproved, 'message' => 'BSCS Curriculum 2026-2027 was approved by the Dean and is ready for your review.'],
        ], $plan);
    }

    public function test_executive_approve_notifies_the_submitter(): void
    {
        $plan = CurriculumTransitionNotificationPlan::forAction('executive_approve', 'BSCS Curriculum 2026-2027', null);

        self::assertSame([
            ['audience' => 'submitter', 'type' => NotificationType::CurriculumExecutiveApproved, 'message' => 'BSCS Curriculum 2026-2027 was approved and is now Active.'],
        ], $plan);
    }

    public function test_dean_return_notifies_the_submitter_with_the_reason(): void
    {
        $plan = CurriculumTransitionNotificationPlan::forAction('dean_return', 'BSCS Curriculum 2026-2027', 'Missing PATHFIT 2.');

        self::assertSame([
            ['audience' => 'submitter', 'type' => NotificationType::CurriculumReturned, 'message' => 'Dean returned BSCS Curriculum 2026-2027. Reason: Missing PATHFIT 2.'],
        ], $plan);
    }

    public function test_executive_return_notifies_the_submitter_with_the_reason(): void
    {
        $plan = CurriculumTransitionNotificationPlan::forAction('executive_return', 'BSCS Curriculum 2026-2027', 'Units mismatch on ITP2.');

        self::assertSame([
            ['audience' => 'submitter', 'type' => NotificationType::CurriculumReturned, 'message' => 'Executive Director returned BSCS Curriculum 2026-2027. Reason: Units mismatch on ITP2.'],
        ], $plan);
    }

    public function test_unknown_action_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CurriculumTransitionNotificationPlan::forAction('unknown', 'BSCS Curriculum 2026-2027', null);
    }
}

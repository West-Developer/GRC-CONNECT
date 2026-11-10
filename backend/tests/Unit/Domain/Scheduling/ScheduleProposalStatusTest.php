<?php

namespace Tests\Unit\Domain\Scheduling;

use App\Domain\Scheduling\ScheduleProposalStatus;
use PHPUnit\Framework\TestCase;

final class ScheduleProposalStatusTest extends TestCase
{
    public function test_status_values_are_the_prd_lifecycle_in_order(): void
    {
        self::assertSame(
            ['draft', 'dean_approved', 'executive_approved', 'published', 'closed'],
            array_column(ScheduleProposalStatus::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Draft', ScheduleProposalStatus::Draft->label());
        self::assertSame('Dean Approved', ScheduleProposalStatus::DeanApproved->label());
        self::assertSame('Executive Approved', ScheduleProposalStatus::ExecutiveApproved->label());
        self::assertSame('Published', ScheduleProposalStatus::Published->label());
        self::assertSame('Closed', ScheduleProposalStatus::Closed->label());
    }

    public function test_only_published_and_closed_are_visible_to_learners(): void
    {
        self::assertFalse(ScheduleProposalStatus::Draft->isVisibleToLearners());
        self::assertFalse(ScheduleProposalStatus::DeanApproved->isVisibleToLearners());
        self::assertFalse(ScheduleProposalStatus::ExecutiveApproved->isVisibleToLearners());
        self::assertTrue(ScheduleProposalStatus::Published->isVisibleToLearners());
        self::assertTrue(ScheduleProposalStatus::Closed->isVisibleToLearners());
    }
}

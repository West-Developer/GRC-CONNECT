<?php

namespace Tests\Unit\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Models\ScheduleProposal;
use App\Models\User;
use App\Policies\ScheduleProposalPolicy;
use PHPUnit\Framework\TestCase;

final class ScheduleProposalPolicyTest extends TestCase
{
    public function test_program_chair_can_view_only_their_college_proposal(): void
    {
        $chair = new User;
        $chair->forceFill([
            'role' => UserRole::ProgramChair,
            'college' => CollegeCode::Ccs,
        ]);

        $ownProposal = new ScheduleProposal;
        $ownProposal->forceFill([
            'college' => CollegeCode::Ccs->value,
            'status' => ScheduleProposalStatus::Draft,
        ]);

        $otherProposal = new ScheduleProposal;
        $otherProposal->forceFill([
            'college' => CollegeCode::Coe->value,
            'status' => ScheduleProposalStatus::Draft,
        ]);

        $policy = new ScheduleProposalPolicy;

        self::assertTrue($policy->view($chair, $ownProposal));
        self::assertFalse($policy->view($chair, $otherProposal));
    }
}

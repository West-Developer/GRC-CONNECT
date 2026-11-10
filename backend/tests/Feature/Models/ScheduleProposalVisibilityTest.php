<?php

namespace Tests\Feature\Models;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Scheduling\ScheduleProposalStatus;
use App\Models\AcademicTerm;
use App\Models\ScheduleProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScheduleProposalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $role->value.'@grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
    }

    public function test_learner_scoped_role_sees_only_published_and_closed_proposals(): void
    {
        $chair = $this->makeUser(UserRole::ProgramChair);
        $termId = $this->makeTerm()->id;
        ScheduleProposal::create(['academic_term_id' => $termId, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::Published]);
        ScheduleProposal::create(['academic_term_id' => $termId, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::Draft]);

        $student = $this->makeUser(UserRole::Student);

        self::assertSame(1, ScheduleProposal::query()->visibleTo($student)->count());
    }

    public function test_planning_role_sees_every_proposal_regardless_of_status(): void
    {
        $chair = $this->makeUser(UserRole::ProgramChair);
        $termId = $this->makeTerm()->id;
        ScheduleProposal::create(['academic_term_id' => $termId, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::Published]);
        ScheduleProposal::create(['academic_term_id' => $termId, 'submitted_by' => $chair->id, 'status' => ScheduleProposalStatus::Draft]);

        $dean = $this->makeUser(UserRole::Dean);

        self::assertSame(2, ScheduleProposal::query()->visibleTo($dean)->count());
    }
}

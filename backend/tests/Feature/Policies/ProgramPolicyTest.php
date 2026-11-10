<?php

namespace Tests\Feature\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\Program;
use App\Models\User;
use App\Policies\ProgramPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProgramPolicyTest extends TestCase
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

    public function test_every_role_may_view_any(): void
    {
        $policy = new ProgramPolicy;

        self::assertTrue($policy->viewAny($this->makeUser(UserRole::Student)));
        self::assertTrue($policy->viewAny($this->makeUser(UserRole::ProgramChair)));
    }

    public function test_a_learner_scoped_role_cannot_view_an_inactive_program_by_direct_id(): void
    {
        $policy = new ProgramPolicy;
        $student = $this->makeUser(UserRole::Student);
        $inactive = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Inactive]);

        self::assertFalse($policy->view($student, $inactive));
    }

    public function test_a_planning_role_can_view_an_inactive_program_by_direct_id(): void
    {
        $policy = new ProgramPolicy;
        $chair = $this->makeUser(UserRole::ProgramChair);
        $inactive = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Inactive]);

        self::assertTrue($policy->view($chair, $inactive));
    }
}

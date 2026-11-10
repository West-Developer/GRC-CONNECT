<?php

namespace Tests\Feature\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Policies\ScheduleProposalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScheduleProposalPolicyTest extends TestCase
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

    public function test_only_a_program_chair_may_create(): void
    {
        $policy = new ScheduleProposalPolicy;

        self::assertTrue($policy->create($this->makeUser(UserRole::ProgramChair)));
        self::assertFalse($policy->create($this->makeUser(UserRole::Dean)));
    }

    public function test_only_a_dean_may_approve_as_dean(): void
    {
        $policy = new ScheduleProposalPolicy;

        self::assertTrue($policy->approveAsDean($this->makeUser(UserRole::Dean)));
        self::assertFalse($policy->approveAsDean($this->makeUser(UserRole::ExecutiveDirector)));
    }

    public function test_only_an_executive_director_may_approve_as_executive(): void
    {
        $policy = new ScheduleProposalPolicy;

        self::assertTrue($policy->approveAsExecutive($this->makeUser(UserRole::ExecutiveDirector)));
        self::assertFalse($policy->approveAsExecutive($this->makeUser(UserRole::Dean)));
    }

    public function test_only_an_executive_director_may_publish(): void
    {
        $policy = new ScheduleProposalPolicy;

        self::assertTrue($policy->publish($this->makeUser(UserRole::ExecutiveDirector)));
        self::assertFalse($policy->publish($this->makeUser(UserRole::RegistrarHead)));
    }

    public function test_only_a_registrar_head_may_close(): void
    {
        $policy = new ScheduleProposalPolicy;

        self::assertTrue($policy->close($this->makeUser(UserRole::RegistrarHead)));
        self::assertFalse($policy->close($this->makeUser(UserRole::ExecutiveDirector)));
    }
}

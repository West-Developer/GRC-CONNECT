<?php

namespace Tests\Feature\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Policies\SubjectOfferingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubjectOfferingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_program_chair_may_view_any(): void
    {
        $policy = new SubjectOfferingPolicy;

        self::assertTrue($policy->viewAny($this->makeUser(UserRole::ProgramChair)));

        foreach (UserRole::cases() as $role) {
            if ($role === UserRole::ProgramChair) {
                continue;
            }

            self::assertFalse($policy->viewAny($this->makeUser($role)), "{$role->value} should not be able to view subject offerings.");
        }
    }

    public function test_only_the_program_chair_may_create(): void
    {
        $policy = new SubjectOfferingPolicy;

        self::assertTrue($policy->create($this->makeUser(UserRole::ProgramChair)));

        foreach (UserRole::cases() as $role) {
            if ($role === UserRole::ProgramChair) {
                continue;
            }

            self::assertFalse($policy->create($this->makeUser($role)), "{$role->value} should not be able to replace subject offerings.");
        }
    }

    private function makeUser(UserRole $role): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $role->value.'.offering@grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }
}

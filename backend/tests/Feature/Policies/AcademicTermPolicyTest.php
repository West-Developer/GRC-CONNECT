<?php

namespace Tests\Feature\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\User;
use App\Policies\AcademicTermPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicTermPolicyTest extends TestCase
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
        $policy = new AcademicTermPolicy;

        self::assertTrue($policy->viewAny($this->makeUser(UserRole::Faculty)));
        self::assertTrue($policy->viewAny($this->makeUser(UserRole::RegistrarHead)));
    }

    public function test_a_learner_scoped_role_cannot_view_a_planning_term_by_direct_id(): void
    {
        $policy = new AcademicTermPolicy;
        $faculty = $this->makeUser(UserRole::Faculty);
        $planning = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);

        self::assertFalse($policy->view($faculty, $planning));
    }

    public function test_a_planning_role_can_view_a_planning_term_by_direct_id(): void
    {
        $policy = new AcademicTermPolicy;
        $registrarHead = $this->makeUser(UserRole::RegistrarHead);
        $planning = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);

        self::assertTrue($policy->view($registrarHead, $planning));
    }

    public function test_only_the_registrar_head_may_create(): void
    {
        $policy = new AcademicTermPolicy;

        self::assertTrue($policy->create($this->makeUser(UserRole::RegistrarHead)));

        foreach (UserRole::cases() as $role) {
            if ($role === UserRole::RegistrarHead) {
                continue;
            }

            self::assertFalse($policy->create($this->makeUser($role)), "{$role->value} should not be able to create a term.");
        }
    }
}

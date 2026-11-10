<?php

namespace Tests\Feature\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Policies\DashboardPolicy;
use App\Policies\StuckEnrollmentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $role->value.'.dashboard@grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_enrollment_summary_is_visible_to_dean_executive_director_program_chair_and_registrar_head(): void
    {
        $policy = new DashboardPolicy;

        self::assertTrue($policy->viewEnrollmentSummary($this->makeUser(UserRole::Dean)));
        self::assertTrue($policy->viewEnrollmentSummary($this->makeUser(UserRole::ExecutiveDirector)));
        self::assertTrue($policy->viewEnrollmentSummary($this->makeUser(UserRole::ProgramChair)));
        self::assertTrue($policy->viewEnrollmentSummary($this->makeUser(UserRole::RegistrarHead)));
        self::assertFalse($policy->viewEnrollmentSummary($this->makeUser(UserRole::Student)));
    }

    public function test_institution_summary_is_visible_only_to_executive_director(): void
    {
        $policy = new DashboardPolicy;

        self::assertTrue($policy->viewInstitutionSummary($this->makeUser(UserRole::ExecutiveDirector)));
        self::assertFalse($policy->viewInstitutionSummary($this->makeUser(UserRole::Dean)));
        self::assertFalse($policy->viewInstitutionSummary($this->makeUser(UserRole::RegistrarHead)));
    }

    public function test_policy_settings_is_visible_only_to_registrar_head(): void
    {
        $policy = new DashboardPolicy;

        self::assertTrue($policy->viewPolicySettings($this->makeUser(UserRole::RegistrarHead)));
        self::assertFalse($policy->viewPolicySettings($this->makeUser(UserRole::Dean)));
        self::assertFalse($policy->viewPolicySettings($this->makeUser(UserRole::ExecutiveDirector)));
    }

    public function test_stuck_enrollments_is_visible_only_to_dean_and_registrar_head(): void
    {
        $policy = new StuckEnrollmentPolicy;

        self::assertTrue($policy->viewAny($this->makeUser(UserRole::Dean)));
        self::assertFalse($policy->viewAny($this->makeUser(UserRole::ExecutiveDirector)));
        self::assertTrue($policy->viewAny($this->makeUser(UserRole::RegistrarHead)));
    }
}

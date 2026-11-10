<?php

namespace Tests\Feature\Policies;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\Subject;
use App\Models\User;
use App\Policies\SubjectPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubjectPolicyTest extends TestCase
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
        $policy = new SubjectPolicy;

        self::assertTrue($policy->viewAny($this->makeUser(UserRole::Student)));
        self::assertTrue($policy->viewAny($this->makeUser(UserRole::ProgramChair)));
    }

    public function test_a_learner_scoped_role_cannot_view_an_inactive_subject_by_direct_id(): void
    {
        $policy = new SubjectPolicy;
        $student = $this->makeUser(UserRole::Student);
        $inactive = Subject::create(['code' => 'CS999', 'title' => 'Deprecated', 'units' => 3, 'status' => SubjectStatus::Inactive]);

        self::assertFalse($policy->view($student, $inactive));
    }

    public function test_a_planning_role_can_view_an_inactive_subject_by_direct_id(): void
    {
        $policy = new SubjectPolicy;
        $chair = $this->makeUser(UserRole::ProgramChair);
        $inactive = Subject::create(['code' => 'CS999', 'title' => 'Deprecated', 'units' => 3, 'status' => SubjectStatus::Inactive]);

        self::assertTrue($policy->view($chair, $inactive));
    }
}

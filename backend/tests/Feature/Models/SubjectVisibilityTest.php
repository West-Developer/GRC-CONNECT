<?php

namespace Tests\Feature\Models;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubjectVisibilityTest extends TestCase
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

    public function test_learner_scoped_role_sees_only_active_subjects(): void
    {
        Subject::create(['code' => 'CS101', 'title' => 'Intro to Programming', 'units' => 3, 'status' => SubjectStatus::Active]);
        Subject::create(['code' => 'CS999', 'title' => 'Deprecated Subject', 'units' => 3, 'status' => SubjectStatus::Inactive]);

        $student = $this->makeUser(UserRole::Student);

        $visible = Subject::query()->visibleTo($student)->pluck('code')->all();

        self::assertSame(['CS101'], $visible);
    }

    public function test_planning_role_sees_every_subject_regardless_of_status(): void
    {
        Subject::create(['code' => 'CS101', 'title' => 'Intro to Programming', 'units' => 3, 'status' => SubjectStatus::Active]);
        Subject::create(['code' => 'CS999', 'title' => 'Deprecated Subject', 'units' => 3, 'status' => SubjectStatus::Inactive]);

        $chair = $this->makeUser(UserRole::ProgramChair);

        $visible = Subject::query()->visibleTo($chair)->pluck('code')->sort()->values()->all();

        self::assertSame(['CS101', 'CS999'], $visible);
    }
}

<?php

namespace Tests\Feature\Models;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CurriculumVisibilityTest extends TestCase
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

    private function makeProgram(): Program
    {
        return Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
    }

    public function test_learner_scoped_role_does_not_see_a_draft_curriculum(): void
    {
        $program = $this->makeProgram();
        Curriculum::create(['program_id' => $program->id, 'name' => 'Active Curriculum', 'effective_school_year' => '2025-2026', 'status' => CurriculumStatus::Active]);
        Curriculum::create(['program_id' => $program->id, 'name' => 'Draft Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Draft]);

        $faculty = $this->makeUser(UserRole::Faculty);

        $visible = Curriculum::query()->visibleTo($faculty)->pluck('name')->all();

        self::assertSame(['Active Curriculum'], $visible);
    }

    public function test_planning_role_sees_every_curriculum_including_draft(): void
    {
        $program = $this->makeProgram();
        Curriculum::create(['program_id' => $program->id, 'name' => 'Active Curriculum', 'effective_school_year' => '2025-2026', 'status' => CurriculumStatus::Active]);
        Curriculum::create(['program_id' => $program->id, 'name' => 'Draft Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Draft]);

        $chair = $this->makeUser(UserRole::ProgramChair);

        $visible = Curriculum::query()->visibleTo($chair)->count();

        self::assertSame(2, $visible);
    }
}

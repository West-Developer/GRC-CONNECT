<?php

namespace Tests\Feature\Models;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AcademicTermVisibilityTest extends TestCase
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

    public function test_learner_scoped_role_does_not_see_a_planning_term(): void
    {
        AcademicTerm::create(['school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterClosed]);
        AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);

        $faculty = $this->makeUser(UserRole::Faculty);

        $visible = AcademicTerm::query()->visibleTo($faculty)->pluck('semester')->all();

        self::assertSame(['2nd'], $visible);
    }

    public function test_planning_role_sees_every_term_including_planning(): void
    {
        AcademicTerm::create(['school_year' => '2025-2026', 'semester' => '2nd', 'status' => AcademicTermStatus::SemesterClosed]);
        AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::Draft]);

        $registrarHead = $this->makeUser(UserRole::RegistrarHead);

        $visible = AcademicTerm::query()->visibleTo($registrarHead)->count();

        self::assertSame(2, $visible);
    }
}

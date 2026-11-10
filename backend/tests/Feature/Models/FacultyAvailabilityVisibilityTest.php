<?php

namespace Tests\Feature\Models;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\FacultyAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacultyAvailabilityVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role, string $handle): User
    {
        return User::create([
            'name' => 'Test '.$role->value,
            'email' => $handle.'@grc.test',
            'password' => 'irrelevant-password',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
    }

    public function test_a_faculty_member_sees_only_their_own_availability(): void
    {
        $term = $this->makeTerm();
        $professorA = $this->makeUser(UserRole::Faculty, 'professor-a');
        $professorB = $this->makeUser(UserRole::Faculty, 'professor-b');

        FacultyAvailability::create([
            'professor_id' => $professorA->id, 'academic_term_id' => $term->id,
            'day_of_week' => 1, 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
        ]);
        FacultyAvailability::create([
            'professor_id' => $professorB->id, 'academic_term_id' => $term->id,
            'day_of_week' => 2, 'starts_at_time' => '10:00:00', 'ends_at_time' => '11:00:00',
        ]);

        $visible = FacultyAvailability::query()->visibleTo($professorA)->pluck('professor_id')->all();

        self::assertSame([$professorA->id], $visible);
    }

    public function test_a_planning_role_sees_every_professors_availability(): void
    {
        $term = $this->makeTerm();
        $professorA = $this->makeUser(UserRole::Faculty, 'professor-a');
        $professorB = $this->makeUser(UserRole::Faculty, 'professor-b');

        FacultyAvailability::create([
            'professor_id' => $professorA->id, 'academic_term_id' => $term->id,
            'day_of_week' => 1, 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
        ]);
        FacultyAvailability::create([
            'professor_id' => $professorB->id, 'academic_term_id' => $term->id,
            'day_of_week' => 2, 'starts_at_time' => '10:00:00', 'ends_at_time' => '11:00:00',
        ]);

        $chair = $this->makeUser(UserRole::ProgramChair, 'chair');
        $visible = FacultyAvailability::query()->visibleTo($chair)->pluck('professor_id')->sort()->values()->all();

        self::assertSame([$professorA->id, $professorB->id], $visible);
    }
}

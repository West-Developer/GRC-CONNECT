<?php

namespace Tests\Feature\Models;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\FacultySubjectPreference;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacultySubjectPreferenceVisibilityTest extends TestCase
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

    public function test_a_faculty_member_sees_only_their_own_preferences(): void
    {
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Intro', 'units' => 3, 'status' => SubjectStatus::Active]);
        $professorA = $this->makeUser(UserRole::Faculty, 'professor-a');
        $professorB = $this->makeUser(UserRole::Faculty, 'professor-b');

        FacultySubjectPreference::create([
            'professor_id' => $professorA->id, 'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ]);
        FacultySubjectPreference::create([
            'professor_id' => $professorB->id, 'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ]);

        $visible = FacultySubjectPreference::query()->visibleTo($professorA)->pluck('professor_id')->all();

        self::assertSame([$professorA->id], $visible);
    }

    public function test_a_planning_role_sees_every_professors_preferences(): void
    {
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Intro', 'units' => 3, 'status' => SubjectStatus::Active]);
        $professorA = $this->makeUser(UserRole::Faculty, 'professor-a');
        $professorB = $this->makeUser(UserRole::Faculty, 'professor-b');

        FacultySubjectPreference::create([
            'professor_id' => $professorA->id, 'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ]);
        FacultySubjectPreference::create([
            'professor_id' => $professorB->id, 'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ]);

        $dean = $this->makeUser(UserRole::Dean, 'dean');
        $visible = FacultySubjectPreference::query()->visibleTo($dean)->pluck('professor_id')->sort()->values()->all();

        self::assertSame([$professorA->id, $professorB->id], $visible);
    }
}

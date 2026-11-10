<?php

namespace Tests\Feature\Policies;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\FacultySubjectPreference;
use App\Models\Subject;
use App\Models\User;
use App\Policies\FacultySubjectPreferencePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacultySubjectPreferencePolicyTest extends TestCase
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

    private function makePreference(User $professor): FacultySubjectPreference
    {
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $subject = Subject::create(['code' => 'CS101', 'title' => 'Intro', 'units' => 3, 'status' => SubjectStatus::Active]);

        return FacultySubjectPreference::create([
            'professor_id' => $professor->id, 'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'rank' => 1,
        ]);
    }

    public function test_every_role_may_view_any(): void
    {
        $policy = new FacultySubjectPreferencePolicy;

        self::assertTrue($policy->viewAny($this->makeUser(UserRole::Faculty, 'faculty')));
        self::assertTrue($policy->viewAny($this->makeUser(UserRole::Dean, 'dean')));
    }

    public function test_a_different_faculty_member_cannot_view_it_by_direct_id(): void
    {
        $policy = new FacultySubjectPreferencePolicy;
        $owner = $this->makeUser(UserRole::Faculty, 'owner');
        $other = $this->makeUser(UserRole::Faculty, 'other');
        $preference = $this->makePreference($owner);

        self::assertFalse($policy->view($other, $preference));
    }

    public function test_a_planning_role_can_view_any_professors_preference(): void
    {
        $policy = new FacultySubjectPreferencePolicy;
        $owner = $this->makeUser(UserRole::Faculty, 'owner');
        $dean = $this->makeUser(UserRole::Dean, 'dean');
        $preference = $this->makePreference($owner);

        self::assertTrue($policy->view($dean, $preference));
    }

    public function test_only_the_faculty_role_may_create(): void
    {
        $policy = new FacultySubjectPreferencePolicy;

        self::assertTrue($policy->create($this->makeUser(UserRole::Faculty, 'faculty')));
        self::assertFalse($policy->create($this->makeUser(UserRole::Dean, 'dean')));
    }

    public function test_only_the_owning_faculty_member_may_update_or_delete(): void
    {
        $policy = new FacultySubjectPreferencePolicy;
        $owner = $this->makeUser(UserRole::Faculty, 'owner');
        $other = $this->makeUser(UserRole::Faculty, 'other');
        $preference = $this->makePreference($owner);

        self::assertTrue($policy->update($owner, $preference));
        self::assertTrue($policy->delete($owner, $preference));
        self::assertFalse($policy->update($other, $preference));
        self::assertFalse($policy->delete($other, $preference));
    }
}

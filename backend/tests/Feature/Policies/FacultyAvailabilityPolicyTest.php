<?php

namespace Tests\Feature\Policies;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use App\Models\FacultyAvailability;
use App\Models\User;
use App\Policies\FacultyAvailabilityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacultyAvailabilityPolicyTest extends TestCase
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

    private function makeAvailability(User $professor): FacultyAvailability
    {
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);

        return FacultyAvailability::create([
            'professor_id' => $professor->id, 'academic_term_id' => $term->id,
            'day_of_week' => 1, 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
        ]);
    }

    public function test_every_role_may_view_any(): void
    {
        $policy = new FacultyAvailabilityPolicy;

        self::assertTrue($policy->viewAny($this->makeUser(UserRole::Faculty, 'faculty')));
        self::assertTrue($policy->viewAny($this->makeUser(UserRole::ProgramChair, 'chair')));
    }

    public function test_a_faculty_member_can_view_their_own_availability_by_direct_id(): void
    {
        $policy = new FacultyAvailabilityPolicy;
        $professor = $this->makeUser(UserRole::Faculty, 'professor');
        $availability = $this->makeAvailability($professor);

        self::assertTrue($policy->view($professor, $availability));
    }

    public function test_a_different_faculty_member_cannot_view_it_by_direct_id(): void
    {
        $policy = new FacultyAvailabilityPolicy;
        $owner = $this->makeUser(UserRole::Faculty, 'owner');
        $other = $this->makeUser(UserRole::Faculty, 'other');
        $availability = $this->makeAvailability($owner);

        self::assertFalse($policy->view($other, $availability));
    }

    public function test_a_planning_role_can_view_any_professors_availability(): void
    {
        $policy = new FacultyAvailabilityPolicy;
        $owner = $this->makeUser(UserRole::Faculty, 'owner');
        $chair = $this->makeUser(UserRole::ProgramChair, 'chair');
        $availability = $this->makeAvailability($owner);

        self::assertTrue($policy->view($chair, $availability));
    }

    public function test_only_the_faculty_role_may_create(): void
    {
        $policy = new FacultyAvailabilityPolicy;

        self::assertTrue($policy->create($this->makeUser(UserRole::Faculty, 'faculty')));
        self::assertFalse($policy->create($this->makeUser(UserRole::ProgramChair, 'chair')));
    }

    public function test_only_the_owning_faculty_member_may_update_or_delete(): void
    {
        $policy = new FacultyAvailabilityPolicy;
        $owner = $this->makeUser(UserRole::Faculty, 'owner');
        $other = $this->makeUser(UserRole::Faculty, 'other');
        $availability = $this->makeAvailability($owner);

        self::assertTrue($policy->update($owner, $availability));
        self::assertTrue($policy->delete($owner, $availability));
        self::assertFalse($policy->update($other, $availability));
        self::assertFalse($policy->delete($other, $availability));
    }
}

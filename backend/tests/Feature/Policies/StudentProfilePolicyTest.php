<?php

namespace Tests\Feature\Policies;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use App\Policies\StudentProfilePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudentProfilePolicyTest extends TestCase
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

    private function makeProfileFor(User $user): StudentProfile
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'STU-'.$user->id,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    public function test_only_admission_staff_may_create(): void
    {
        $policy = new StudentProfilePolicy;

        self::assertTrue($policy->create($this->makeUser(UserRole::AdmissionStaff, 'admission')));
        self::assertFalse($policy->create($this->makeUser(UserRole::RegistrarStaff, 'registrar')));
    }

    public function test_a_student_can_view_their_own_profile(): void
    {
        $policy = new StudentProfilePolicy;
        $student = $this->makeUser(UserRole::Student, 'owner');
        $profile = $this->makeProfileFor($student);

        self::assertTrue($policy->view($student, $profile));
    }

    public function test_a_different_student_cannot_view_it(): void
    {
        $policy = new StudentProfilePolicy;
        $owner = $this->makeUser(UserRole::Student, 'owner');
        $other = $this->makeUser(UserRole::Student, 'other');
        $profile = $this->makeProfileFor($owner);

        self::assertFalse($policy->view($other, $profile));
    }

    public function test_no_planning_role_other_than_admission_staff_has_broad_view_access(): void
    {
        $policy = new StudentProfilePolicy;
        $owner = $this->makeUser(UserRole::Student, 'owner');
        $profile = $this->makeProfileFor($owner);

        self::assertFalse($policy->view($this->makeUser(UserRole::RegistrarHead, 'registrar-head'), $profile));
    }

    public function test_admission_staff_has_broad_view_access_for_the_student_records_directory(): void
    {
        $policy = new StudentProfilePolicy;
        $owner = $this->makeUser(UserRole::Student, 'owner');
        $profile = $this->makeProfileFor($owner);

        self::assertTrue($policy->view($this->makeUser(UserRole::AdmissionStaff, 'admission'), $profile));
    }
}

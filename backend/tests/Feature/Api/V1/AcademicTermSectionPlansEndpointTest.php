<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Organization\SectionPlanStatus;
use App\Models\AcademicTerm;
use App\Models\AcademicTermSectionPlan;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /academic-term-section-plans`: read access for the section-planning workflow (Program Head,
 * Dean, Executive Director) plus the Registrar Head, who reads every college's submitted schedules
 * from the "Submitted Schedules" workspace (S12a) without taking part in the workflow itself.
 */
final class AcademicTermSectionPlansEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenForNewUser(UserRole $role, string $email): string
    {
        $user = User::create(['name' => 'Test User', 'email' => $email, 'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);

        return (string) $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.token');
    }

    private function makePlan(): array
    {
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $program = Program::create(['code' => 'BSIT', 'name' => 'BS IT', 'status' => ProgramStatus::Active, 'college' => 'ccs']);
        $curriculum = Curriculum::create(['program_id' => $program->id, 'name' => 'BSIT Curriculum', 'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active]);
        AcademicTermSectionPlan::create(['academic_term_id' => $term->id, 'curriculum_id' => $curriculum->id, 'college' => 'ccs', 'year_level' => 1, 'section_count' => 1, 'students_per_block' => 40, 'status' => SectionPlanStatus::Submitted]);

        return [$term];
    }

    public function test_the_registrar_head_can_read_submitted_section_plans(): void
    {
        [$term] = $this->makePlan();
        $token = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar.head.plans@grc.test');

        $this->withToken($token)
            ->getJson("/api/v1/academic-term-section-plans?academic_term_id={$term->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_registrar_staff_cannot_read_section_plans(): void
    {
        [$term] = $this->makePlan();
        $token = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar.staff.plans@grc.test');

        $this->withToken($token)
            ->getJson("/api/v1/academic-term-section-plans?academic_term_id={$term->id}")
            ->assertForbidden();
    }

    public function test_a_student_cannot_read_section_plans(): void
    {
        [$term] = $this->makePlan();
        $token = $this->tokenForNewUser(UserRole::Student, 'student.plans@grc.test');

        $this->withToken($token)
            ->getJson("/api/v1/academic-term-section-plans?academic_term_id={$term->id}")
            ->assertForbidden();
    }
}

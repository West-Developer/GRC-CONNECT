<?php

namespace Tests\Feature\Api\V1;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stakeholder Doc 14: the Registrar Head opens a student's profile from the
 * enrollment approvals queue. It is a narrow read; nobody else gains it.
 */
final class StudentRegistrarProfileEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function makeStudent(): StudentProfile
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'effective_start_year' => 2026,
            'effective_end_year' => 2030, 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Sample Student', 'email' => 'sample.student@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2026-0001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 2,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $student = $this->makeStudent();

        $this->getJson("/api/v1/students/{$student->id}/registrar-profile")->assertUnauthorized();
    }

    public function test_the_registrar_head_can_read_a_student_profile_without_any_credential(): void
    {
        $student = $this->makeStudent();
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.profile@grc.test');

        $response = $this->withToken($token)->getJson("/api/v1/students/{$student->id}/registrar-profile");

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.student_number', '2026-0001');
        $response->assertJsonPath('data.name', 'Sample Student');
        $response->assertJsonPath('data.program_code', 'BSCS');
        $response->assertJsonPath('data.year_level', 2);
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.setup_code');
    }

    public function test_no_other_role_can_use_the_registrar_profile_read(): void
    {
        $student = $this->makeStudent();

        foreach ([
            UserRole::RegistrarStaff,
            UserRole::AdmissionStaff,
            UserRole::AccountingStaff,
            UserRole::ProgramChair,
            UserRole::Dean,
        ] as $index => $role) {
            $token = $this->tokenFor($role, "other.{$index}@grc.test");

            $this->withToken($token)
                ->getJson("/api/v1/students/{$student->id}/registrar-profile")
                ->assertForbidden();
        }
    }

    public function test_a_student_cannot_read_a_profile_through_the_registrar_route(): void
    {
        $student = $this->makeStudent();
        $token = $this->tokenFor(UserRole::Student, 'other.student@grc.test');

        $this->withToken($token)
            ->getJson("/api/v1/students/{$student->id}/registrar-profile")
            ->assertForbidden();
    }

    public function test_an_unknown_student_is_a_clean_404(): void
    {
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.missing@grc.test');

        $this->withToken($token)->getJson('/api/v1/students/999999/registrar-profile')->assertNotFound();
    }
}

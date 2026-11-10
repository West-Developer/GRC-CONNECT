<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Scheduling\ScheduleGenerationStatus;
use App\Models\AcademicTerm;
use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacultyLoadReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_program_chair_can_configure_a_college_term_threshold_and_read_the_report(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2027-2028',
            'semester' => '2nd',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $token = $this->tokenFor(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($token)
            ->putJson("/api/v1/academic-terms/{$term->id}/faculty-load-threshold", ['max_units' => 18])
            ->assertOk()
            ->assertJsonPath('data.max_units', 18);
        $this->withToken($token)
            ->getJson("/api/v1/academic-terms/{$term->id}/faculty-load-report")
            ->assertOk()
            ->assertJsonPath('data.college', 'ccs')
            ->assertJsonPath('data.threshold_units', 18)
            ->assertJsonPath('data.equivalent_faculty_loads', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'faculty_load_threshold.updated',
            'auditable_type' => 'faculty_load_threshold',
        ]);
    }

    public function test_a_program_chair_cannot_read_another_colleges_latest_run(): void
    {
        $term = AcademicTerm::create([
            'school_year' => '2027-2028',
            'semester' => '2nd',
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $ccsChair = $this->user(UserRole::ProgramChair, CollegeCode::Ccs);
        $coeToken = $this->tokenFor(UserRole::ProgramChair, CollegeCode::Coe);
        ScheduleGenerationRun::create([
            'academic_term_id' => $term->id,
            'college' => CollegeCode::Ccs->value,
            'initiated_by' => $ccsChair->id,
            'status' => ScheduleGenerationStatus::Succeeded,
        ]);
        $this->flushHeaders();
        $this->withToken($coeToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.college', 'coe');
        $this->withToken($coeToken)
            ->getJson("/api/v1/academic-terms/{$term->id}/schedule-generation-runs/latest")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    private function tokenFor(UserRole $role, CollegeCode $college): string
    {
        return $this->user($role, $college)->createToken('faculty-load-report-test')->plainTextToken;
    }

    private function user(UserRole $role, CollegeCode $college): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => strtolower($college->value).'.'.uniqid().'@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'college' => $college,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

    }
}

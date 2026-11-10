<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use App\Models\ScheduleGenerationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ScheduleGenerationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_a_program_chair_can_start_an_idempotent_schedule_generation_run(): void
    {
        Queue::fake();
        $term = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.generation@grc.test', CollegeCode::Ccs);

        $first = $this->withToken($token)->postJson("/api/v1/academic-terms/{$term->id}/schedule-generation-runs");
        $second = $this->withToken($token)->postJson("/api/v1/academic-terms/{$term->id}/schedule-generation-runs");

        $first->assertCreated()
            ->assertJsonPath('data.type', 'schedule_generation_run')
            ->assertJsonPath('data.academic_term_id', $term->id)
            ->assertJsonPath('data.college', 'ccs')
            ->assertJsonPath('data.status', 'queued');
        $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('schedule_generation_runs', 1);
    }

    public function test_a_program_chair_cannot_start_a_generation_for_another_college(): void
    {
        $term = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.no-college@grc.test');

        $response = $this->withToken($token)->postJson("/api/v1/academic-terms/{$term->id}/schedule-generation-runs");

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertDatabaseCount('schedule_generation_runs', 0);
    }

    public function test_a_completed_generation_can_be_run_again(): void
    {
        Queue::fake();
        $term = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.rerun@grc.test', CollegeCode::Ccs);

        $first = $this->withToken($token)->postJson("/api/v1/academic-terms/{$term->id}/schedule-generation-runs");
        $first->assertCreated();
        ScheduleGenerationRun::query()->findOrFail($first->json('data.id'))->update(['status' => 'succeeded']);

        $this->withToken($token)
            ->postJson("/api/v1/academic-terms/{$term->id}/schedule-generation-runs")
            ->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id') + 1);
        $this->assertDatabaseCount('schedule_generation_runs', 2);
    }

    public function test_a_non_program_chair_cannot_start_schedule_generation(): void
    {
        $term = AcademicTerm::create(['school_year' => '2027-2028', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $token = $this->tokenFor(UserRole::Student, 'student.generation@grc.test');

        $this->withToken($token)->postJson("/api/v1/academic-terms/{$term->id}/schedule-generation-runs")
            ->assertForbidden();
    }

    private function tokenFor(UserRole $role, string $email, ?CollegeCode $college = null): string
    {
        $user = User::create(['name' => 'Test User', 'email' => $email, 'password' => self::PASSWORD, 'role' => $role, 'college' => $college, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);

        return (string) $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.token');
    }
}

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
use App\Models\StudentSchedulePreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudentSchedulePreferencesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    /** @return array{0: Program, 1: Curriculum} */
    private function makeProgramAndCurriculum(): array
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);

        return [$program, $curriculum];
    }

    private function makeStudent(Curriculum $curriculum, string $email): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2026-08-'.random_int(10000, 99999),
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function tokenFor(StudentProfile $student): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $student->user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/student-schedule-preferences')->assertUnauthorized();
        $this->putJson('/api/v1/student-schedule-preferences', [])->assertUnauthorized();
    }

    public function test_a_student_saves_and_reads_back_their_schedule_preference(): void
    {
        [, $curriculum] = $this->makeProgramAndCurriculum();
        $student = $this->makeStudent($curriculum, 'student.prefs@grc.test');
        $token = $this->tokenFor($student);

        $this->withToken($token)->putJson('/api/v1/student-schedule-preferences', [
            'preferred_days' => [1, 2, 3],
            'preferred_time_block' => 'morning',
            'max_days_on_campus' => 3,
            'avoid_early_first_class' => true,
        ])->assertOk()->assertJsonPath('data.preferred_time_block', 'morning');

        $this->withToken($token)->getJson('/api/v1/student-schedule-preferences')
            ->assertOk()->assertJsonPath('data.max_days_on_campus', 3);
    }

    public function test_it_returns_defaults_when_no_preference_is_set(): void
    {
        [, $curriculum] = $this->makeProgramAndCurriculum();
        $student = $this->makeStudent($curriculum, 'student.defaults@grc.test');
        $token = $this->tokenFor($student);

        $response = $this->withToken($token)->getJson('/api/v1/student-schedule-preferences');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.id', null);
        $response->assertJsonPath('data.preferred_time_block', 'any');
        $response->assertJsonPath('data.avoid_early_first_class', false);
        $response->assertJsonPath('data.preferred_days', null);
    }

    public function test_it_rejects_sunday_in_preferred_days(): void
    {
        [, $curriculum] = $this->makeProgramAndCurriculum();
        $student = $this->makeStudent($curriculum, 'student.sunday@grc.test');
        $token = $this->tokenFor($student);

        $this->withToken($token)->putJson('/api/v1/student-schedule-preferences', [
            'preferred_days' => [7],
        ])->assertStatus(422);
    }

    public function test_preferred_modality_must_be_a_current_section_modality(): void
    {
        [, $curriculum] = $this->makeProgramAndCurriculum();
        $student = $this->makeStudent($curriculum, 'student.modality@grc.test');
        $token = $this->tokenFor($student);

        // 'online' was retired by 2026_08_09_000004 — it must no longer
        // validate, even though it once did.
        $this->withToken($token)->putJson('/api/v1/student-schedule-preferences', [
            'preferred_modality' => 'online',
        ])->assertStatus(422);

        $this->withToken($token)->putJson('/api/v1/student-schedule-preferences', [
            'preferred_modality' => 'hyflex_a',
        ])->assertOk()->assertJsonPath('data.preferred_modality', 'hyflex_a');
    }

    /**
     * The route carries no {id} — a student can never address another
     * student's row by construction. The one real cross-boundary vector is
     * a non-student role token, which StudentSchedulePreferencePolicy
     * rejects with 403 (same shape as EligibleSubjectsEndpointTest's
     * test_a_non_student_role_is_forbidden). See
     * test_each_students_preference_is_scoped_to_their_own_record below for
     * the complementary "never sees another's data" coverage.
     */
    public function test_a_student_cannot_read_another_students_preference(): void
    {
        User::create([
            'name' => 'Faculty', 'email' => 'faculty.prefs@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Faculty, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'faculty.prefs@grc.test', 'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/student-schedule-preferences')->assertForbidden();
        $this->withToken($token)->putJson('/api/v1/student-schedule-preferences', [])->assertForbidden();
    }

    /**
     * Student A's row is seeded directly via Eloquent, not through the PUT
     * endpoint — chaining a second, different authenticated user in the
     * same test method hits the Sanctum guard-caching quirk documented in
     * PROGRESS.md, so this test authenticates as exactly one user (student
     * B) and never logs in as student A at all.
     */
    public function test_each_students_preference_is_scoped_to_their_own_record(): void
    {
        [, $curriculum] = $this->makeProgramAndCurriculum();

        $studentA = $this->makeStudent($curriculum, 'student.a.prefs@grc.test');
        StudentSchedulePreference::create([
            'student_id' => $studentA->id,
            'preferred_days' => [1],
            'preferred_time_block' => 'evening',
        ]);

        $studentB = $this->makeStudent($curriculum, 'student.b.prefs@grc.test');
        $response = $this->withToken($this->tokenFor($studentB))->getJson('/api/v1/student-schedule-preferences');

        $response->assertOk()->assertJsonPath('data.preferred_time_block', 'any');
    }
}

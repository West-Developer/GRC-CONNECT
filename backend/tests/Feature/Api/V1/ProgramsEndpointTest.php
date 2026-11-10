<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProgramsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email, UserStatus $status = UserStatus::Active, ?CollegeCode $college = null): string
    {
        User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'college' => $college,
            'status' => $status,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function seedPrograms(): void
    {
        Program::create(['code' => 'BSIT', 'name' => 'BS Information Technology', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Active]);
        Program::create(['code' => 'BSCRIM', 'name' => 'BS Criminology', 'college' => CollegeCode::Ccs, 'status' => ProgramStatus::Inactive]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/programs')->assertUnauthorized();
    }

    public function test_a_user_disabled_after_token_issuance_is_rejected(): void
    {
        // Disabled users cannot log in at all, so exercise EnsureUserIsActive
        // via a still-valid token belonging to a user disabled afterward.
        $token = $this->tokenFor(UserRole::Student, 'later-disabled@grc.test');
        User::where('email', 'later-disabled@grc.test')->update(['status' => UserStatus::Disabled->value]);

        $this->withToken($token)->getJson('/api/v1/programs')->assertUnauthorized();
    }

    public function test_a_student_receives_only_active_programs_with_the_exact_envelope(): void
    {
        $this->seedPrograms();
        $token = $this->tokenFor(UserRole::Student, 'student.programs@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/programs');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'data' => [
                    [
                        'type' => 'program',
                        'id' => Program::where('code', 'BSIT')->sole()->id,
                        'code' => 'BSIT',
                        'name' => 'BS Information Technology',
                        'status' => 'active',
                        'status_label' => 'Active',
                    ],
                ],
            ]);
    }

    public function test_a_program_chair_receives_every_program_in_their_college_regardless_of_status(): void
    {
        $this->seedPrograms();
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.programs@grc.test', college: CollegeCode::Ccs);

        $response = $this->withToken($token)->getJson('/api/v1/programs');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        self::assertSame(['BSCRIM', 'BSIT'], $codes);
    }

    public function test_results_are_ordered_by_code_ascending(): void
    {
        Program::create(['code' => 'ZZZ', 'name' => 'Z Program', 'status' => ProgramStatus::Active]);
        Program::create(['code' => 'AAA', 'name' => 'A Program', 'status' => ProgramStatus::Active]);
        $token = $this->tokenFor(UserRole::Dean, 'dean.programs@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/programs');

        self::assertSame(['AAA', 'ZZZ'], collect($response->json('data'))->pluck('code')->all());
    }
}

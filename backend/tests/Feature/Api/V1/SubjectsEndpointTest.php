<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubjectsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/subjects')->assertUnauthorized();
    }

    public function test_a_student_receives_only_active_subjects(): void
    {
        Subject::create(['code' => 'CS101', 'title' => 'Intro to Programming', 'units' => 3, 'status' => SubjectStatus::Active]);
        Subject::create(['code' => 'CS999', 'title' => 'Deprecated', 'units' => 3, 'status' => SubjectStatus::Inactive]);
        $token = $this->tokenFor(UserRole::Student, 'student.subjects@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/subjects');

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(['CS101'], collect($response->json('data'))->pluck('code')->all());
    }

    public function test_a_program_chair_receives_every_subject_regardless_of_status(): void
    {
        Subject::create(['code' => 'CS101', 'title' => 'Intro to Programming', 'units' => 3, 'status' => SubjectStatus::Active]);
        Subject::create(['code' => 'CS999', 'title' => 'Deprecated', 'units' => 3, 'status' => SubjectStatus::Inactive]);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.subjects@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/subjects');

        $response->assertOk();
        self::assertSame(['CS101', 'CS999'], collect($response->json('data'))->pluck('code')->all());
    }

    public function test_the_response_carries_the_pairing_metadata_the_room_scheduler_needs(): void
    {
        $lab = Subject::create(['code' => 'PROG1L', 'title' => 'Computer Programming 1 LAB', 'units' => 1, 'status' => SubjectStatus::Active, 'college' => 'ccs', 'room_requirement' => 'laboratory']);
        Subject::create(['code' => 'PROG1', 'title' => 'Computer Programming 1 LEC', 'units' => 3, 'status' => SubjectStatus::Active, 'college' => 'ccs', 'paired_subject_id' => $lab->id, 'room_requirement' => 'lecture']);
        $token = $this->tokenFor(UserRole::ProgramChair, 'chair.subject-pairing@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/subjects')->assertOk();

        $bySubject = collect($response->json('data'))->keyBy('code');
        self::assertSame('ccs', $bySubject['PROG1']['college']);
        self::assertSame($lab->id, $bySubject['PROG1']['paired_subject_id']);
        self::assertSame('lecture', $bySubject['PROG1']['room_requirement']);
        self::assertNull($bySubject['PROG1L']['paired_subject_id']);
    }
}

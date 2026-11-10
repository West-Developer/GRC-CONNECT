<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\FacultyAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacultyAvailabilitiesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function tokenFor(UserRole $role, string $email): array
    {
        $user = User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        return [$user, $token];
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/faculty-availabilities')->assertUnauthorized();
        $this->postJson('/api/v1/faculty-availabilities', [])->assertUnauthorized();
    }

    public function test_a_faculty_member_can_create_their_own_availability(): void
    {
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'professor.create@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/faculty-availabilities', [
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.professor_id', $professor->id);
        $this->assertDatabaseHas('faculty_availabilities', ['professor_id' => $professor->id, 'day_of_week' => 1]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $professor->id,
            'action' => AuditAction::FACULTY_AVAILABILITY_CREATED,
            'auditable_id' => $response->json('data.id'),
        ]);
        self::assertSame(1, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_it_stores_an_availability_window_without_an_academic_term(): void
    {
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'professor.term-independent@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/faculty-availabilities', [
            'day_of_week' => 2,
            'starts_at_time' => '09:00:00',
            'ends_at_time' => '12:00:00',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('faculty_availabilities', [
            'professor_id' => $professor->id,
            'day_of_week' => 2,
            'origin' => 'declared',
        ]);
    }

    public function test_it_rejects_sunday(): void
    {
        [, $token] = $this->tokenFor(UserRole::Faculty, 'professor.sunday@grc.test');

        $this->withToken($token)->postJson('/api/v1/faculty-availabilities', [
            'day_of_week' => 7,
            'starts_at_time' => '09:00:00',
            'ends_at_time' => '12:00:00',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_non_faculty_role_cannot_create_availability(): void
    {
        [, $token] = $this->tokenFor(UserRole::ProgramChair, 'chair.create@grc.test');

        $response = $this->withToken($token)->postJson('/api/v1/faculty-availabilities', [
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_declaring_the_same_slot_twice_is_rejected_with_a_clean_422(): void
    {
        [, $token] = $this->tokenFor(UserRole::Faculty, 'professor.dup@grc.test');

        $this->withToken($token)->postJson('/api/v1/faculty-availabilities', [
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
        ])->assertCreated();

        $response = $this->withToken($token)->postJson('/api/v1/faculty-availabilities', [
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00', 'ends_at_time' => '10:00:00',
        ]);

        $response->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_declaring_over_a_seeded_slot_replaces_it(): void
    {
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'professor.seeded-slot@grc.test');
        FacultyAvailability::create([
            'professor_id' => $professor->id,
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '12:00:00',
            'origin' => 'workbook_seeded',
        ]);

        $this->withToken($token)->postJson('/api/v1/faculty-availabilities', [
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '17:00:00',
        ])->assertCreated();

        $this->assertDatabaseCount('faculty_availabilities', 1);
        $this->assertDatabaseHas('faculty_availabilities', [
            'professor_id' => $professor->id,
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '17:00:00',
            'origin' => 'declared',
        ]);
    }

    public function test_updating_over_a_seeded_slot_replaces_it(): void
    {
        [$professor, $token] = $this->tokenFor(UserRole::Faculty, 'professor.update-seeded-slot@grc.test');
        FacultyAvailability::create([
            'professor_id' => $professor->id,
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '17:00:00',
            'origin' => 'workbook_seeded',
        ]);
        $declared = FacultyAvailability::create([
            'professor_id' => $professor->id,
            'day_of_week' => 2,
            'starts_at_time' => '09:00:00',
            'ends_at_time' => '10:00:00',
            'origin' => 'declared',
        ]);

        $response = $this->withToken($token)->patchJson("/api/v1/faculty-availabilities/{$declared->id}", [
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('faculty_availabilities', 1);
        $this->assertDatabaseHas('faculty_availabilities', [
            'id' => $declared->id,
            'professor_id' => $professor->id,
            'day_of_week' => 1,
            'starts_at_time' => '08:00:00',
            'ends_at_time' => '09:00:00',
            'origin' => 'declared',
        ]);
    }

    public function test_a_faculty_member_sees_only_their_own_availability_in_the_index(): void
    {
        [$professorA, $tokenA] = $this->tokenFor(UserRole::Faculty, 'professor.a@grc.test');
        [$professorB] = $this->tokenFor(UserRole::Faculty, 'professor.b@grc.test');

        FacultyAvailability::create([
            'professor_id' => $professorA->id,
            'day_of_week' => 1, 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
        ]);
        FacultyAvailability::create([
            'professor_id' => $professorB->id,
            'day_of_week' => 2, 'starts_at_time' => '10:00:00', 'ends_at_time' => '11:00:00',
        ]);

        $response = $this->withToken($tokenA)->getJson('/api/v1/faculty-availabilities');

        $response->assertOk();
        self::assertSame([$professorA->id], collect($response->json('data'))->pluck('professor_id')->all());
    }

    public function test_a_faculty_member_cannot_update_another_professors_availability(): void
    {
        [$owner] = $this->tokenFor(UserRole::Faculty, 'owner@grc.test');
        [, $otherToken] = $this->tokenFor(UserRole::Faculty, 'other@grc.test');

        $availability = FacultyAvailability::create([
            'professor_id' => $owner->id,
            'day_of_week' => 1, 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
        ]);

        $response = $this->withToken($otherToken)->patchJson("/api/v1/faculty-availabilities/{$availability->id}", [
            'day_of_week' => 3,
            'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('faculty_availabilities', ['id' => $availability->id, 'day_of_week' => 1]);
    }

    public function test_a_faculty_member_can_update_and_delete_their_own_availability(): void
    {
        [$owner, $token] = $this->tokenFor(UserRole::Faculty, 'owner.update@grc.test');

        $availability = FacultyAvailability::create([
            'professor_id' => $owner->id,
            'day_of_week' => 1, 'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
        ]);

        $updateResponse = $this->withToken($token)->patchJson("/api/v1/faculty-availabilities/{$availability->id}", [
            'day_of_week' => 3,
            'starts_at_time' => '08:00:00', 'ends_at_time' => '09:00:00',
        ]);
        $updateResponse->assertOk()->assertJsonPath('data.day_of_week', 3);

        $deleteResponse = $this->withToken($token)->deleteJson("/api/v1/faculty-availabilities/{$availability->id}");
        $deleteResponse->assertNoContent();
        $this->assertDatabaseMissing('faculty_availabilities', ['id' => $availability->id]);
    }
}

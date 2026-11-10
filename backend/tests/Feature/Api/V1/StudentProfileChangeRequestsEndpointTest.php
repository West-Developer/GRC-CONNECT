<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\ProgramStatus;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Notification;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\StudentProfileChangeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class StudentProfileChangeRequestsEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, StudentProfile} */
    private function student(string $email = 'profile.student@grc.test'): array
    {
        $program = Program::firstOrCreate(['code' => 'BSED'], [
            'name' => 'Bachelor of Secondary Education',
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::firstOrCreate([
            'program_id' => $program->id,
            'effective_start_year' => 2026,
        ], [
            'name' => 'BSED 2026 Curriculum',
            'effective_school_year' => '2026-2027',
            'effective_end_year' => 2030,
            'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Official Student',
            'first_name' => 'Official',
            'last_name' => 'Student',
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2026-08-'.str_pad((string) (2000 + $user->id), 5, '0', STR_PAD_LEFT),
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'entry_year' => 2026,
            'year_level' => 1,
            'enrollment_category' => 'regular',
            'admission_status' => 'admitted',
            'academic_standing' => 'good',
            'address' => 'Official Address, Caloocan City',
        ]);

        return [$user, $profile];
    }

    public function test_student_views_official_information_and_submits_a_pending_personal_change(): void
    {
        [$user, $profile] = $this->student();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/student-profile')
            ->assertOk()
            ->assertJsonPath('data.name', 'Official Student')
            ->assertJsonPath('data.email', 'profile.student@grc.test')
            ->assertJsonPath('data.address', 'Official Address, Caloocan City');

        $response = $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => 'Requested',
            'last_name' => 'Student',
            'email' => 'requested.student@grc.test',
            'address' => 'Requested Complete Address, Caloocan City',
            'reason' => 'My personal information needs correction.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested.name', 'Requested Student')
            ->assertJsonPath('data.official.name', 'Official Student');

        $requestId = $response->json('data.id');
        self::assertIsInt($requestId);
        $this->assertDatabaseHas('student_profile_change_requests', [
            'id' => $requestId,
            'student_id' => $profile->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Official Student',
            'email' => 'profile.student@grc.test',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'id' => $profile->id,
            'address' => 'Official Address, Caloocan City',
        ]);

        $this->getJson('/api/v1/student-profile-change-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestId);

        $creationAudit = AuditLog::query()->where('action', AuditAction::STUDENT_PROFILE_CHANGE_REQUESTED)->sole();
        $creationPayload = json_encode([$creationAudit->before_values, $creationAudit->after_values], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Requested Student', $creationPayload);
        self::assertStringNotContainsString('requested.student@grc.test', $creationPayload);
        self::assertStringNotContainsString('Requested Complete Address', $creationPayload);
        self::assertStringNotContainsString('My personal information needs correction.', $creationPayload);
    }

    public function test_student_can_revise_or_cancel_the_only_pending_request(): void
    {
        [$user] = $this->student('revision.student@grc.test');
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => 'First',
            'last_name' => 'Requested',
            'email' => 'revision.student@grc.test',
            'address' => 'First Requested Address',
            'reason' => 'First correction request.',
        ])->assertCreated();
        $requestId = $created->json('data.id');

        $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => 'Duplicate',
            'last_name' => 'Request',
            'email' => 'revision.student@grc.test',
            'address' => 'Duplicate Address',
            'reason' => 'This should not create a second pending request.',
        ])->assertConflict();

        $this->patchJson('/api/v1/student-profile-change-requests/'.$requestId, [
            'first_name' => 'Revised',
            'last_name' => 'Requested',
            'email' => 'revised.student@grc.test',
            'address' => 'Revised Requested Address',
            'reason' => 'Updated correction details.',
        ])->assertOk()
            ->assertJsonPath('data.requested.name', 'Revised Requested')
            ->assertJsonPath('data.requested.email', 'revised.student@grc.test');

        $revisionAudit = AuditLog::query()->where('action', AuditAction::STUDENT_PROFILE_CHANGE_REQUEST_UPDATED)->sole();
        $revisionPayload = json_encode([$revisionAudit->before_values, $revisionAudit->after_values], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Revised Requested', $revisionPayload);
        self::assertStringNotContainsString('revised.student@grc.test', $revisionPayload);
        self::assertStringNotContainsString('Revised Requested Address', $revisionPayload);
        self::assertStringNotContainsString('Updated correction details.', $revisionPayload);

        $this->deleteJson('/api/v1/student-profile-change-requests/'.$requestId)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => 'New',
            'last_name' => 'Requested',
            'email' => 'revision.student@grc.test',
            'address' => 'New Requested Address',
            'reason' => 'A new request after cancellation.',
        ])->assertCreated();
    }

    public function test_student_cannot_submit_a_no_op_request_or_change_another_students_request(): void
    {
        [$owner, $ownerProfile] = $this->student('owner.student@grc.test');
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => $owner->first_name,
            'last_name' => $owner->last_name,
            'email' => $owner->email,
            'address' => $ownerProfile->address,
            'reason' => 'No actual change.',
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['profile']]]);

        $requestId = $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => 'Owner',
            'last_name' => 'Requested',
            'email' => $owner->email,
            'address' => $ownerProfile->address,
            'reason' => 'Correct my name.',
        ])->assertCreated()->json('data.id');

        [$other] = $this->student('other.student@grc.test');
        Sanctum::actingAs($other);

        $this->patchJson('/api/v1/student-profile-change-requests/'.$requestId, [
            'first_name' => 'Unauthorized',
            'last_name' => 'Revision',
            'email' => $other->email,
            'address' => 'Unauthorized Address',
            'reason' => 'Unauthorized.',
        ])->assertForbidden();
        $this->deleteJson('/api/v1/student-profile-change-requests/'.$requestId)->assertForbidden();
    }

    public function test_admission_approves_the_complete_change_atomically_after_in_person_verification(): void
    {
        [$studentUser, $profile] = $this->student('approval.student@grc.test');
        Sanctum::actingAs($studentUser);
        $requestId = $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => 'Approved',
            'last_name' => 'Student',
            'email' => 'approved.student@grc.test',
            'address' => 'Approved Complete Address, Caloocan City',
            'reason' => 'Correct the official record.',
        ])->assertCreated()->json('data.id');

        $admission = User::create([
            'name' => 'Admission Approver',
            'email' => 'admission.approver@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::AdmissionStaff,
            'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($admission);

        $this->getJson('/api/v1/student-profile-change-requests?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestId);

        $this->patchJson('/api/v1/student-profile-change-requests/'.$requestId.'/decision', [
            'action' => 'approve',
            'identity_verified_in_person' => false,
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/student-profile-change-requests/'.$requestId.'/decision', [
            'action' => 'approve',
            'identity_verified_in_person' => true,
            'notes' => 'Identity and supporting documents checked at Admission.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.official.name', 'Approved Student')
            ->assertJsonPath('data.official.email', 'approved.student@grc.test')
            ->assertJsonPath('data.official.address', 'Approved Complete Address, Caloocan City');

        $this->assertDatabaseHas('users', [
            'id' => $studentUser->id,
            'name' => 'Approved Student',
            'email' => 'approved.student@grc.test',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'id' => $profile->id,
            'address' => 'Approved Complete Address, Caloocan City',
        ]);
        $this->assertDatabaseHas('student_profile_change_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'decided_by' => $admission->id,
        ]);
        self::assertSame(NotificationType::StudentProfileChangeApproved, Notification::query()->sole()->type);
        $approvalAudit = AuditLog::query()->where('action', AuditAction::STUDENT_PROFILE_CHANGE_REQUEST_APPROVED)->sole();
        $approvalPayload = json_encode([$approvalAudit->before_values, $approvalAudit->after_values], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Approved Student', $approvalPayload);
        self::assertStringNotContainsString('approved.student@grc.test', $approvalPayload);
        self::assertStringNotContainsString('Approved Complete Address', $approvalPayload);
        self::assertStringNotContainsString('Correct the official record.', $approvalPayload);
        self::assertStringNotContainsString('Identity and supporting documents checked at Admission.', $approvalPayload);
    }

    public function test_rejection_requires_notes_and_preserves_the_official_record(): void
    {
        [$studentUser, $profile] = $this->student('rejection.student@grc.test');
        Sanctum::actingAs($studentUser);
        $requestId = $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => 'Rejected',
            'last_name' => 'Requested',
            'email' => 'rejected.request@grc.test',
            'address' => 'Rejected Requested Address',
            'reason' => 'Requested correction.',
        ])->assertCreated()->json('data.id');

        $admission = User::create([
            'name' => 'Admission Reviewer',
            'email' => 'admission.reviewer@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::AdmissionStaff,
            'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profile-change-requests/'.$requestId.'/decision', [
            'action' => 'reject',
            'identity_verified_in_person' => true,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['notes']]]);

        $this->patchJson('/api/v1/student-profile-change-requests/'.$requestId.'/decision', [
            'action' => 'reject',
            'identity_verified_in_person' => true,
            'notes' => 'The submitted information did not match the documents presented.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->patchJson('/api/v1/student-profile-change-requests/'.$requestId.'/decision', [
            'action' => 'reject',
            'identity_verified_in_person' => true,
            'notes' => 'Repeated decision must be rejected.',
        ])->assertConflict();

        $this->assertDatabaseHas('users', [
            'id' => $studentUser->id,
            'name' => 'Official Student',
            'email' => 'rejection.student@grc.test',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'id' => $profile->id,
            'address' => 'Official Address, Caloocan City',
        ]);
        self::assertSame(NotificationType::StudentProfileChangeRejected, Notification::query()->sole()->type);
        $rejectionAudit = AuditLog::query()->where('action', AuditAction::STUDENT_PROFILE_CHANGE_REQUEST_REJECTED)->sole();
        $rejectionPayload = json_encode([$rejectionAudit->before_values, $rejectionAudit->after_values], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Rejected Requested', $rejectionPayload);
        self::assertStringNotContainsString('rejected.request@grc.test', $rejectionPayload);
        self::assertStringNotContainsString('Rejected Requested Address', $rejectionPayload);
        self::assertStringNotContainsString('The submitted information did not match the documents presented.', $rejectionPayload);
    }

    public function test_approval_conflicts_leave_the_official_record_and_pending_request_unchanged(): void
    {
        [$studentUser, $profile] = $this->student('conflict.student@grc.test');
        User::create([
            'name' => 'Existing Email Owner',
            'email' => 'already.used@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($studentUser);
        $requestId = $this->postJson('/api/v1/student-profile-change-requests', [
            'first_name' => 'Conflicting',
            'last_name' => 'Requested',
            'email' => 'already.used@grc.test',
            'address' => 'Conflicting Requested Address',
            'reason' => 'Request with an email that became unavailable.',
        ])->assertCreated()->json('data.id');

        $admission = User::create([
            'name' => 'Admission Conflict Reviewer',
            'email' => 'admission.conflict@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::AdmissionStaff,
            'status' => UserStatus::Active,
        ]);
        Sanctum::actingAs($admission);

        $this->patchJson('/api/v1/student-profile-change-requests/'.$requestId.'/decision', [
            'action' => 'approve',
            'identity_verified_in_person' => true,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['email']]]);

        $this->assertDatabaseHas('student_profile_change_requests', [
            'id' => $requestId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $studentUser->id,
            'name' => 'Official Student',
            'email' => 'conflict.student@grc.test',
        ]);

        $changeRequest = StudentProfileChangeRequest::query()->findOrFail($requestId);
        $changeRequest->forceFill(['requested_email' => 'available.after.revision@grc.test'])->save();
        $profile->forceFill([
            'address' => 'Official Address Changed After Request',
            'updated_at' => now()->addMinute(),
        ])->saveQuietly();

        $this->patchJson('/api/v1/student-profile-change-requests/'.$requestId.'/decision', [
            'action' => 'approve',
            'identity_verified_in_person' => true,
        ])->assertConflict();

        $this->assertDatabaseHas('student_profile_change_requests', [
            'id' => $requestId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('notifications', 0);
    }
}

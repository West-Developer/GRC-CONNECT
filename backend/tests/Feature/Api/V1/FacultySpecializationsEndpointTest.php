<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacultySpecializationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    /** @return array{User, string} */
    private function faculty(string $email, CollegeCode $college = CollegeCode::Ccs): array
    {
        $faculty = User::create([
            'name' => 'Test Faculty',
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => UserRole::Faculty,
            'college' => $college,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        return [$faculty, $token];
    }

    private function subject(string $code, CollegeCode $college): Subject
    {
        return Subject::create([
            'code' => $code,
            'college' => $college,
            'title' => 'Test Subject '.$code,
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);
    }

    /** @return array{User, string} */
    private function programChair(string $email, CollegeCode $college = CollegeCode::Ccs): array
    {
        $chair = User::create([
            'name' => 'Test Chair',
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => UserRole::ProgramChair,
            'college' => $college,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        return [$chair, $token];
    }

    public function test_a_professor_declares_and_lists_their_teaching_specializations(): void
    {
        [$professor, $token] = $this->faculty('faculty.specialization@grc.test');
        $subject = $this->subject('IT101', CollegeCode::Ccs);

        $this->withToken($token)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $subject->id,
            'proficiency' => 'primary',
            'notes' => 'Experienced in introductory computing.',
        ])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'faculty-specialization')
            ->assertJsonPath('data.professor_id', $professor->id)
            ->assertJsonPath('data.subject_id', $subject->id)
            ->assertJsonPath('data.proficiency', 'primary')
            ->assertJsonPath('data.proficiency_label', 'Primary');

        $this->withToken($token)->getJson('/api/v1/faculty-specializations')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.professor_id', $professor->id);
    }

    public function test_it_rejects_a_duplicate_subject_with_the_validation_error_envelope(): void
    {
        [, $token] = $this->faculty('faculty.specialization-duplicate@grc.test');
        $subject = $this->subject('IT102', CollegeCode::Ccs);

        $payload = ['subject_id' => $subject->id, 'proficiency' => 'secondary'];
        $this->withToken($token)->postJson('/api/v1/faculty-specializations', $payload)->assertCreated();

        $this->withToken($token)->postJson('/api/v1/faculty-specializations', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.errors.subject_id.0', 'The subject id has already been taken.');
    }

    public function test_it_rejects_a_subject_outside_the_professors_college(): void
    {
        [, $token] = $this->faculty('faculty.specialization-college@grc.test', CollegeCode::Ccs);
        $otherCollegeSubject = $this->subject('ED101', CollegeCode::Coe);

        $this->withToken($token)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $otherCollegeSubject->id,
            'proficiency' => 'secondary',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.errors.subject_id.0', 'The selected subject id is invalid.');
    }

    public function test_a_professor_cannot_delete_another_professors_specialization(): void
    {
        [$owner, $ownerToken] = $this->faculty('faculty.specialization-owner@grc.test');
        [$other, $otherToken] = $this->faculty('faculty.specialization-other@grc.test');
        self::assertNotSame($owner->id, $other->id);
        $subject = $this->subject('IT103', CollegeCode::Ccs);

        $specializationId = $this->withToken($ownerToken)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $subject->id,
            'proficiency' => 'secondary',
        ])->assertCreated()->json('data.id');

        // The test client reuses the cached Sanctum guard after the owner's
        // POST. Forget it before sending a different bearer token, matching
        // the real fresh-request behavior covered by SessionEndpointTest.
        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken)->deleteJson("/api/v1/faculty-specializations/{$specializationId}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseHas('faculty_specializations', ['id' => $specializationId]);
    }

    public function test_a_newly_declared_specialization_is_pending_and_exposes_status_fields(): void
    {
        [, $token] = $this->faculty('faculty.specialization-status@grc.test');
        $subject = $this->subject('IT104', CollegeCode::Ccs);

        $this->withToken($token)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $subject->id,
            'proficiency' => 'primary',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Pending')
            ->assertJsonPath('data.decided_at', null)
            ->assertJsonPath('data.decision_reason', null);
    }

    public function test_a_program_chair_assigns_a_specialization_to_a_professor_in_their_college_and_it_is_auto_approved(): void
    {
        [$chair, $chairToken] = $this->programChair('chair.specialization@grc.test', CollegeCode::Ccs);
        [$professor] = $this->faculty('faculty.assigned-by-chair@grc.test', CollegeCode::Ccs);
        $subject = $this->subject('IT105', CollegeCode::Ccs);

        $this->withToken($chairToken)->postJson('/api/v1/faculty-specializations', [
            'professor_id' => $professor->id,
            'subject_id' => $subject->id,
            'proficiency' => 'primary',
        ])->assertCreated()
            ->assertJsonPath('data.professor_id', $professor->id)
            ->assertJsonPath('data.source', 'program_chair_assigned')
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.status_label', 'Approved');
    }

    public function test_a_program_chair_cannot_assign_a_specialization_to_a_professor_outside_their_college(): void
    {
        [, $chairToken] = $this->programChair('chair.specialization-other@grc.test', CollegeCode::Ccs);
        [$otherCollegeProfessor] = $this->faculty('faculty.other-college@grc.test', CollegeCode::Coe);
        $subject = $this->subject('ED102', CollegeCode::Coe);

        $this->withToken($chairToken)->postJson('/api/v1/faculty-specializations', [
            'professor_id' => $otherCollegeProfessor->id,
            'subject_id' => $subject->id,
            'proficiency' => 'primary',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_program_chair_must_specify_a_professor_when_declaring_a_specialization(): void
    {
        [, $chairToken] = $this->programChair('chair.no-professor@grc.test', CollegeCode::Ccs);
        $subject = $this->subject('IT112', CollegeCode::Ccs);

        $this->withToken($chairToken)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $subject->id,
            'proficiency' => 'primary',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.errors.professor_id.0', 'Select the professor you are assigning this subject to.');
    }

    public function test_a_program_chair_approves_a_pending_specialization_in_their_college(): void
    {
        [$chair, $chairToken] = $this->programChair('chair.decide-approve@grc.test', CollegeCode::Ccs);
        [, $facultyToken] = $this->faculty('faculty.decide-approve@grc.test', CollegeCode::Ccs);
        $subject = $this->subject('IT106', CollegeCode::Ccs);

        $specializationId = $this->withToken($facultyToken)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $subject->id,
            'proficiency' => 'secondary',
        ])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();

        $this->withToken($chairToken)->patchJson("/api/v1/faculty-specializations/{$specializationId}", [
            'action' => 'approve',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.status_label', 'Approved');

        $this->assertDatabaseHas('faculty_specializations', [
            'id' => $specializationId,
            'status' => 'approved',
            'decided_by' => $chair->id,
        ]);
    }

    public function test_rejecting_a_specialization_requires_a_reason_and_notifies_the_professor(): void
    {
        [, $chairToken] = $this->programChair('chair.decide-reject@grc.test', CollegeCode::Ccs);
        [$professor, $facultyToken] = $this->faculty('faculty.decide-reject@grc.test', CollegeCode::Ccs);
        $subject = $this->subject('IT107', CollegeCode::Ccs);

        $specializationId = $this->withToken($facultyToken)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $subject->id,
            'proficiency' => 'secondary',
        ])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();

        $this->withToken($chairToken)->patchJson("/api/v1/faculty-specializations/{$specializationId}", [
            'action' => 'reject',
        ])->assertUnprocessable()
            ->assertJsonPath('error.errors.reason.0', 'A reason is required for this action.');

        $this->withToken($chairToken)->patchJson("/api/v1/faculty-specializations/{$specializationId}", [
            'action' => 'reject',
            'reason' => 'Not enough evidence of teaching experience.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decision_reason', 'Not enough evidence of teaching experience.');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $professor->id,
            'type' => 'faculty_specialization_rejected',
        ]);
    }

    public function test_a_program_chair_cannot_decide_a_specialization_outside_their_college(): void
    {
        [, $chairToken] = $this->programChair('chair.decide-other@grc.test', CollegeCode::Ccs);
        [, $otherFacultyToken] = $this->faculty('faculty.decide-other@grc.test', CollegeCode::Coe);
        $subject = $this->subject('ED103', CollegeCode::Coe);

        $specializationId = $this->withToken($otherFacultyToken)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $subject->id,
            'proficiency' => 'secondary',
        ])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();

        $this->withToken($chairToken)->patchJson("/api/v1/faculty-specializations/{$specializationId}", [
            'action' => 'approve',
        ])->assertForbidden();
    }

    public function test_a_specialization_cannot_be_decided_twice(): void
    {
        [, $chairToken] = $this->programChair('chair.decide-twice@grc.test', CollegeCode::Ccs);
        [, $facultyToken] = $this->faculty('faculty.decide-twice@grc.test', CollegeCode::Ccs);
        $subject = $this->subject('IT108', CollegeCode::Ccs);

        $specializationId = $this->withToken($facultyToken)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $subject->id,
            'proficiency' => 'secondary',
        ])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();

        $this->withToken($chairToken)->patchJson("/api/v1/faculty-specializations/{$specializationId}", ['action' => 'approve'])
            ->assertOk();

        $this->withToken($chairToken)->patchJson("/api/v1/faculty-specializations/{$specializationId}", ['action' => 'approve'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_a_program_chair_only_sees_specializations_for_their_own_college(): void
    {
        [, $chairToken] = $this->programChair('chair.visibility@grc.test', CollegeCode::Ccs);
        [$ownProfessor, $ownToken] = $this->faculty('faculty.visibility-own@grc.test', CollegeCode::Ccs);
        [, $otherToken] = $this->faculty('faculty.visibility-other@grc.test', CollegeCode::Coe);
        $ownSubject = $this->subject('IT109', CollegeCode::Ccs);
        $otherSubject = $this->subject('ED104', CollegeCode::Coe);

        $this->withToken($ownToken)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $ownSubject->id,
            'proficiency' => 'secondary',
        ])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $otherSubject->id,
            'proficiency' => 'secondary',
        ])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->withToken($chairToken)->getJson('/api/v1/faculty-specializations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.professor_id', $ownProfessor->id);
    }

    public function test_faculty_specializations_can_be_filtered_by_professor_id(): void
    {
        [, $chairToken] = $this->programChair('chair.filter@grc.test', CollegeCode::Ccs);
        [$firstProfessor, $firstToken] = $this->faculty('faculty.filter-first@grc.test', CollegeCode::Ccs);
        [$secondProfessor, $secondToken] = $this->faculty('faculty.filter-second@grc.test', CollegeCode::Ccs);
        $subject = $this->subject('IT110', CollegeCode::Ccs);

        $this->withToken($firstToken)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $subject->id,
            'proficiency' => 'secondary',
        ])->assertCreated();
        $this->app['auth']->forgetGuards();
        $anotherSubject = $this->subject('IT111', CollegeCode::Ccs);
        $this->withToken($secondToken)->postJson('/api/v1/faculty-specializations', [
            'subject_id' => $anotherSubject->id,
            'proficiency' => 'secondary',
        ])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->withToken($chairToken)->getJson("/api/v1/faculty-specializations?professor_id={$firstProfessor->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.professor_id', $firstProfessor->id);

        self::assertNotSame($firstProfessor->id, $secondProfessor->id);
    }
}

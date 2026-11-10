<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\GradeStatus;
use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use App\Models\Notification;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\TransfereeCredit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credit mapping (Doc "WESTLIE", ADR 0026): the Student asks, the Program
 * Chair maps and endorses, and only Registrar Staff approves. Every test
 * authenticates as exactly one actor (the documented Sanctum test gotcha), so
 * a state that a second actor would have produced is seeded through Eloquent.
 */
final class TransfereeCreditsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private int $sequence = 0;

    private function makeCurriculum(?CollegeCode $college = null, string $code = 'BSCS'): Curriculum
    {
        $program = Program::create([
            'code' => $code, 'name' => 'BS '.$code, 'status' => ProgramStatus::Active,
            'college' => $college,
        ]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => $code.' Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeStudent(Curriculum $curriculum): StudentProfile
    {
        $this->sequence++;
        $user = User::create([
            'name' => 'Test Student '.$this->sequence, 'email' => "student.transfer{$this->sequence}@grc.test",
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => sprintf('2026-%04d', $this->sequence),
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function makeUser(UserRole $role, ?CollegeCode $college = null): User
    {
        $this->sequence++;

        return User::create([
            'name' => 'Test '.$role->value, 'email' => "{$role->value}.transfer{$this->sequence}@grc.test",
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            'college' => $college,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function makeSubject(string $code, string $title, float $units = 3.0): Subject
    {
        return Subject::create(['code' => $code, 'title' => $title, 'units' => $units, 'status' => SubjectStatus::Active]);
    }

    private function makeCredit(StudentProfile $student, ?int $subjectId = null, TransfereeCreditStatus $status = TransfereeCreditStatus::Pending): TransfereeCredit
    {
        return TransfereeCredit::create([
            'student_id' => $student->id,
            'source_institution' => 'Other University',
            'source_subject_code' => 'EXT101',
            'source_subject_title' => 'Introduction to Programming',
            'source_grade' => '1.75',
            'credited_units' => 3,
            'source_school_year' => '2023-2024',
            'source_semester' => '1st',
            'subject_id' => $subjectId,
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function studentRequestPayload(array $overrides = []): array
    {
        return array_merge([
            'source_institution' => 'Technological Institute of the Philippines',
            'source_subject_code' => 'CS101',
            'source_subject_title' => 'Intro to Computing',
            'source_grade' => '1.50',
            'credited_units' => 1.5,
            'source_school_year' => '2023-2024',
            'source_semester' => '1st',
        ], $overrides);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/transferee-credits')->assertUnauthorized();
        $this->postJson('/api/v1/transferee-credits', [])->assertUnauthorized();
    }

    // ---------------------------------------------------------------- request

    public function test_a_student_can_request_a_credit_mapping_for_themselves(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        // A GRC subject with the same code exists, and must NOT be mapped for
        // the student: the mapping is the Program Chair's decision.
        $this->makeSubject('CS101', 'Introduction to Computing');

        $response = $this->withToken($this->tokenFor($student->user))
            ->postJson('/api/v1/transferee-credits', $this->studentRequestPayload());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.student_number', $student->student_number)
            ->assertJsonPath('data.subject_id', null)
            ->assertJsonPath('data.credited_units', 1.5)
            ->assertJsonPath('data.requested_by_student', true);
        $credit = TransfereeCredit::query()->sole();
        self::assertSame($student->id, $credit->student_id);
        self::assertSame($student->user_id, $credit->requested_by);
        self::assertNull($credit->subject_id);
        self::assertNull($credit->submitted_at);
        self::assertSame(AuditAction::TRANSFEREE_CREDIT_CREATED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_a_student_can_never_request_a_credit_for_someone_else(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        $other = $this->makeStudent($curriculum);

        $this->withToken($this->tokenFor($student->user))
            ->postJson('/api/v1/transferee-credits', $this->studentRequestPayload(['student_id' => $other->id]))
            ->assertCreated();

        self::assertSame($student->id, TransfereeCredit::query()->sole()->student_id);
    }

    public function test_a_student_cannot_pick_the_subject_the_credit_maps_to(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');

        $this->withToken($this->tokenFor($student->user))
            ->postJson('/api/v1/transferee-credits', $this->studentRequestPayload(['subject_id' => $subject->id]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('transferee_credits', 0);
    }

    public function test_a_student_request_needs_the_school_year_and_semester(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->postJson('/api/v1/transferee-credits', $this->studentRequestPayload(['source_school_year' => null]))
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/transferee-credits', $this->studentRequestPayload(['source_semester' => null]))
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/transferee-credits', $this->studentRequestPayload(['credited_units' => null]))
            ->assertUnprocessable();
    }

    public function test_the_subject_code_is_optional_on_a_student_request(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));

        $this->withToken($this->tokenFor($student->user))
            ->postJson('/api/v1/transferee-credits', $this->studentRequestPayload(['source_subject_code' => null]))
            ->assertCreated()
            ->assertJsonPath('data.source_subject_code', '');
    }

    public function test_saving_the_same_subject_twice_returns_the_open_one_instead_of_a_duplicate(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $token = $this->tokenFor($student->user);

        $first = $this->withToken($token)->postJson('/api/v1/transferee-credits', $this->studentRequestPayload())
            ->assertCreated();
        $second = $this->withToken($token)->postJson('/api/v1/transferee-credits', $this->studentRequestPayload([
            'source_institution' => '  technological institute of the philippines ',
        ]))->assertOk();

        self::assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('transferee_credits', 1);
        // Nothing is sent to the Program Chair until the student submits the list.
        self::assertSame(0, Notification::query()->where('user_id', $chair->id)->count());
    }

    public function test_a_submission_notifies_only_the_program_chairs_of_the_students_college(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $this->makeCredit($student, null, TransfereeCreditStatus::Draft);
        $ownChair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $otherChair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Coe);
        $registrar = $this->makeUser(UserRole::RegistrarStaff);

        $this->withToken($this->tokenFor($student->user))
            ->postJson('/api/v1/transferee-credits/submit')
            ->assertOk();

        $notification = Notification::query()->where('user_id', $ownChair->id)->sole();
        self::assertSame(NotificationType::TransfereeCreditRequested, $notification->type);
        self::assertSame(0, Notification::query()->where('user_id', $otherChair->id)->count());
        self::assertSame(0, Notification::query()->where('user_id', $registrar->id)->count());
    }

    public function test_a_program_chair_can_record_and_map_a_credit_for_a_student_in_their_college(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($chair))->postJson('/api/v1/transferee-credits', [
            'student_id' => $student->id,
            'source_institution' => 'Technological Institute of the Philippines',
            'source_subject_code' => 'CS101',
            'source_subject_title' => 'Intro to Computing',
            'source_grade' => '1.50',
            'credited_units' => 3,
            'subject_id' => $subject->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subject_code', 'CS101')
            ->assertJsonPath('data.requested_by_student', false);
        // The chair recorded it herself: nothing to notify her about.
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_a_program_chair_cannot_record_a_credit_for_another_colleges_student(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Coe, 'BSCE'));
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->postJson('/api/v1/transferee-credits', [
            'student_id' => $student->id,
            'source_institution' => 'Other University',
            'source_subject_title' => 'Statics',
            'credited_units' => 3,
        ])->assertForbidden();

        $this->assertDatabaseCount('transferee_credits', 0);
    }

    public function test_registrar_staff_cannot_record_a_credit(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $staff = $this->makeUser(UserRole::RegistrarStaff);

        $this->withToken($this->tokenFor($staff))->postJson('/api/v1/transferee-credits', [
            'student_id' => $student->id,
            'source_institution' => 'Other University',
            'source_subject_title' => 'Introduction to Programming',
            'credited_units' => 3,
        ])->assertForbidden();

        $this->assertDatabaseCount('transferee_credits', 0);
    }

    public function test_registrar_head_cannot_record_a_credit(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($head))->postJson('/api/v1/transferee-credits', [
            'student_id' => $student->id,
            'source_institution' => 'Other University',
            'source_subject_title' => 'Introduction to Programming',
            'credited_units' => 3,
        ])->assertForbidden();

        $this->assertDatabaseCount('transferee_credits', 0);
    }

    public function test_credited_units_may_be_fractional_and_is_required(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $token = $this->tokenFor($chair);
        $base = [
            'student_id' => $student->id,
            'source_institution' => 'Other University',
            'source_subject_title' => 'Leadership',
        ];

        $this->withToken($token)->postJson('/api/v1/transferee-credits', $base)->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/transferee-credits', $base + ['credited_units' => 1.5])
            ->assertCreated()
            ->assertJsonPath('data.credited_units', 1.5);
    }

    // ------------------------------------------------------------ chair review

    public function test_a_program_chair_maps_and_edits_a_pending_credit(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($chair))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'credited_units' => 4.5,
            'subject_id' => $subject->id,
        ]);

        $response->assertOk()->assertJsonPath('data.subject_code', 'CS101');
        $credit->refresh();
        self::assertSame(4.5, $credit->credited_units);
        self::assertSame($subject->id, $credit->subject_id);
        self::assertSame(TransfereeCreditStatus::Pending, $credit->status);
        self::assertSame(AuditAction::TRANSFEREE_CREDIT_UPDATED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_a_program_chair_cannot_touch_another_colleges_credit(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Coe, 'BSCE'));
        $credit = $this->makeCredit($student);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'credited_units' => 4,
        ])->assertForbidden();

        self::assertSame(3.0, $credit->refresh()->credited_units);
    }

    public function test_endorsing_needs_a_mapped_subject(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'endorse',
        ])->assertUnprocessable()
            ->assertJsonPath('error.errors.subject_id.0', 'Map this credit to a subject before endorsing it.');

        self::assertSame(TransfereeCreditStatus::Pending, $credit->refresh()->status);
    }

    public function test_a_program_chair_endorses_a_mapped_credit_and_the_registrar_head_is_told(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, $subject->id);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $staff = $this->makeUser(UserRole::RegistrarStaff);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $response = $this->withToken($this->tokenFor($chair))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'endorse',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'endorsed');
        $credit->refresh();
        self::assertSame(TransfereeCreditStatus::Endorsed, $credit->status);
        self::assertSame($chair->id, $credit->endorsed_by);
        self::assertNotNull($credit->endorsed_at);
        self::assertSame(
            AuditAction::TRANSFEREE_CREDIT_ENDORSED,
            AuditLog::query()->where('auditable_type', 'transferee_credit')->sole()->action,
        );
        self::assertSame(NotificationType::TransfereeCreditEndorsed, Notification::query()->where('user_id', $head->id)->sole()->type);
        self::assertSame(0, Notification::query()->where('user_id', $staff->id)->count());
    }

    public function test_a_program_chair_can_map_and_endorse_in_one_request(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'endorse', 'subject_id' => $subject->id, 'credited_units' => 3,
        ])->assertOk()->assertJsonPath('data.status', 'endorsed');

        self::assertSame($subject->id, $credit->refresh()->subject_id);
    }

    public function test_a_program_chair_declines_a_pending_request_with_a_reason_and_the_student_is_told(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $token = $this->tokenFor($chair);

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'decline'])
            ->assertUnprocessable();

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'decline', 'reason' => 'No official transcript attached.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        self::assertSame(
            'No official transcript attached.',
            AuditLog::query()->where('auditable_type', 'transferee_credit')->sole()->reason,
        );
        $notification = Notification::query()->where('user_id', $student->user_id)->sole();
        self::assertSame(NotificationType::TransfereeCreditRejected, $notification->type);
    }

    public function test_a_program_chair_can_never_approve_or_reject_a_credit(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, $subject->id, TransfereeCreditStatus::Endorsed);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $token = $this->tokenFor($chair);

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'approve'])
            ->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'reject', 'reason' => 'x'])
            ->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'return', 'reason' => 'x'])
            ->assertForbidden();

        self::assertSame(TransfereeCreditStatus::Endorsed, $credit->refresh()->status);
    }

    public function test_a_role_that_may_not_decide_gets_403_even_before_the_credit_is_endorsed(): void
    {
        // Authorization is checked before the credit's status, so a Program
        // Chair asking to approve a still-pending credit is refused (403),
        // not told about its status (422).
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'approve',
        ])->assertForbidden();

        self::assertSame(TransfereeCreditStatus::Pending, $credit->refresh()->status);
    }

    public function test_edits_are_rejected_once_endorsed(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, $subject->id, TransfereeCreditStatus::Endorsed);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'credited_units' => 5,
        ])->assertUnprocessable();
    }

    // ------------------------------------------------------ registrar head decision

    public function test_the_registrar_head_approves_an_endorsed_credit(): void
    {
        // This also covers a credit the Program Chair endorsed before the approver moved from
        // Registrar Staff to the Registrar Head: an `endorsed` row simply becomes the Head's.
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, $subject->id, TransfereeCreditStatus::Endorsed);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $response = $this->withToken($this->tokenFor($head))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'approve',
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'approved');
        $credit->refresh();
        self::assertSame(TransfereeCreditStatus::Approved, $credit->status);
        self::assertSame($head->id, $credit->processed_by);
        self::assertNotNull($credit->processed_at);
        self::assertSame(
            AuditAction::TRANSFEREE_CREDIT_APPROVED,
            AuditLog::query()->where('auditable_type', 'transferee_credit')->sole()->action,
        );
        self::assertSame(NotificationType::TransfereeCreditApproved, Notification::query()->where('user_id', $student->user_id)->sole()->type);
    }

    public function test_the_registrar_head_cannot_approve_a_credit_the_chair_has_not_endorsed(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($head))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'approve',
        ])->assertUnprocessable();

        self::assertSame(TransfereeCreditStatus::Pending, $credit->refresh()->status);
    }

    public function test_approving_twice_has_no_second_effect(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, $subject->id, TransfereeCreditStatus::Endorsed);
        $head = $this->makeUser(UserRole::RegistrarHead);
        $token = $this->tokenFor($head);

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'approve'])->assertOk();
        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'approve'])->assertUnprocessable();

        self::assertSame(1, Notification::query()->where('user_id', $student->user_id)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_APPROVED)->count());
    }

    public function test_the_registrar_head_rejects_an_endorsed_credit_requiring_a_reason(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, $subject->id, TransfereeCreditStatus::Endorsed);
        $head = $this->makeUser(UserRole::RegistrarHead);
        $token = $this->tokenFor($head);

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'reject'])
            ->assertUnprocessable();

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'reject', 'reason' => 'Transcript does not match the record.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_the_registrar_head_returns_an_endorsed_credit_to_the_program_chair_with_a_reason(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, $subject->id, TransfereeCreditStatus::Endorsed);
        $credit->forceFill([
            'endorsed_by' => $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs)->id,
            'endorsed_at' => now(),
        ])->save();
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $otherChair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Coe);
        $head = $this->makeUser(UserRole::RegistrarHead);
        $token = $this->tokenFor($head);

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'return'])
            ->assertUnprocessable();

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'return', 'reason' => 'Mapped to the wrong subject.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.return_reason', 'Mapped to the wrong subject.');

        $credit->refresh();
        self::assertSame(TransfereeCreditStatus::Pending, $credit->status);
        self::assertSame($head->id, $credit->returned_by);
        self::assertNotNull($credit->returned_at);
        self::assertSame('Mapped to the wrong subject.', $credit->return_reason);
        self::assertNull($credit->endorsed_by);
        self::assertNull($credit->endorsed_at);
        self::assertSame(
            'Mapped to the wrong subject.',
            AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_RETURNED)->sole()->reason,
        );
        self::assertSame(NotificationType::TransfereeCreditReturned, Notification::query()->where('user_id', $chair->id)->sole()->type);
        self::assertSame(0, Notification::query()->where('user_id', $otherChair->id)->count());
        self::assertSame(0, Notification::query()->where('user_id', $student->user_id)->count());
    }

    public function test_a_pending_credit_cannot_be_returned(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($head))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'return', 'reason' => 'Not endorsed yet.',
        ])->assertUnprocessable();

        self::assertNull($credit->refresh()->returned_at);
    }

    public function test_a_returned_credit_can_be_remapped_and_endorsed_again_and_the_return_is_cleared(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $head = $this->makeUser(UserRole::RegistrarHead);
        $credit = $this->makeCredit($student);
        $credit->forceFill([
            'returned_by' => $head->id,
            'returned_at' => now(),
            'return_reason' => 'Mapped to the wrong subject.',
        ])->save();
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->patchJson("/api/v1/transferee-credits/{$credit->id}", [
            'action' => 'endorse', 'subject_id' => $subject->id,
        ])->assertOk()->assertJsonPath('data.status', 'endorsed')->assertJsonPath('data.return_reason', null);

        $credit->refresh();
        self::assertSame($subject->id, $credit->subject_id);
        self::assertNull($credit->returned_by);
        self::assertNull($credit->returned_at);
        self::assertNull($credit->return_reason);
    }

    public function test_registrar_staff_can_neither_edit_nor_endorse(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, $subject->id);
        $staff = $this->makeUser(UserRole::RegistrarStaff);
        $token = $this->tokenFor($staff);

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['credited_units' => 5])
            ->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'endorse'])
            ->assertForbidden();

        self::assertSame(TransfereeCreditStatus::Pending, $credit->refresh()->status);
    }

    public function test_registrar_staff_can_no_longer_decide_a_transferee_credit(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, $subject->id, TransfereeCreditStatus::Endorsed);
        $staff = $this->makeUser(UserRole::RegistrarStaff);
        $token = $this->tokenFor($staff);

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'approve'])
            ->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'reject', 'reason' => 'x'])
            ->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'return', 'reason' => 'x'])
            ->assertForbidden();

        self::assertSame(TransfereeCreditStatus::Endorsed, $credit->refresh()->status);
    }

    // ---------------------------------------------------------------- visibility

    public function test_a_student_sees_only_their_own_transferee_credit(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $studentA = $this->makeStudent($curriculum);
        $this->makeCredit($studentA);
        $studentB = $this->makeStudent($curriculum);
        $this->makeCredit($studentB);

        $this->withToken($this->tokenFor($studentA->user))->getJson('/api/v1/transferee-credits')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_registrar_staff_and_registrar_head_see_every_transferee_credit(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $this->makeCredit($this->makeStudent($curriculum));
        $this->makeCredit($this->makeStudent($curriculum));
        $staff = $this->makeUser(UserRole::RegistrarStaff);

        $this->withToken($this->tokenFor($staff))->getJson('/api/v1/transferee-credits')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_registrar_head_sees_every_transferee_credit(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $this->makeCredit($this->makeStudent($curriculum));
        $this->makeCredit($this->makeStudent($curriculum));
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($head))->getJson('/api/v1/transferee-credits')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_program_chair_sees_only_their_colleges_credits(): void
    {
        $this->makeCredit($this->makeStudent($this->makeCurriculum(CollegeCode::Ccs, 'BSCS')));
        $this->makeCredit($this->makeStudent($this->makeCurriculum(CollegeCode::Coe, 'BSCE')));
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->getJson('/api/v1/transferee-credits')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    // --------------------------------------------------------------- suggestions

    public function test_a_program_chair_gets_ranked_subject_suggestions_from_the_students_curriculum(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        $exact = $this->makeSubject('CS101', 'Introduction to Computing');
        $similar = $this->makeSubject('CS105', 'Introduction to Computing Systems');
        $unrelated = $this->makeSubject('MATH101', 'College Algebra');
        $outside = $this->makeSubject('CS999', 'Introduction to Computing Elsewhere');
        foreach ([$exact, $similar, $unrelated] as $subject) {
            CurriculumSubject::create([
                'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
                'year_level' => 1, 'semester' => '1st', 'is_required' => true,
            ]);
        }
        $credit = TransfereeCredit::create([
            'student_id' => $student->id, 'source_institution' => 'TIP',
            'source_subject_code' => 'CS 101', 'source_subject_title' => 'Intro to Computing',
            'credited_units' => 3, 'status' => TransfereeCreditStatus::Pending,
        ]);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($chair))
            ->getJson("/api/v1/transferee-credits/{$credit->id}/suggestions")
            ->assertOk();

        $codes = array_column($response->json('data'), 'subject_code');
        self::assertSame('CS101', $codes[0]);
        self::assertContains('CS105', $codes);
        self::assertNotContains('MATH101', $codes);
        // Only subjects on the student's own curriculum are ever suggested.
        self::assertNotContains($outside->code, $codes);
        $top = $response->json('data.0');
        self::assertSame($exact->id, $top['subject_id']);
        self::assertContains('Same subject code', $top['reasons']);
        self::assertGreaterThan($response->json('data.1.score'), $top['score']);
    }

    public function test_suggestions_leave_out_subjects_the_student_already_has(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        $passed = $this->makeSubject('CS101', 'Introduction to Computing');
        $credited = $this->makeSubject('CS102', 'Introduction to Computing Lab');
        $open = $this->makeSubject('CS103', 'Introduction to Computing Concepts');
        foreach ([$passed, $credited, $open] as $subject) {
            CurriculumSubject::create([
                'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
                'year_level' => 1, 'semester' => '1st', 'is_required' => true,
            ]);
        }
        $term = AcademicTerm::create(['school_year' => '2025-2026', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterClosed]);
        AcademicGrade::create([
            'student_id' => $student->id, 'subject_id' => $passed->id, 'academic_term_id' => $term->id,
            'final_grade' => '1.50', 'status' => GradeStatus::Locked, 'encoded_by' => $student->user_id,
        ]);
        $this->makeCredit($student, $credited->id, TransfereeCreditStatus::Approved);
        $credit = TransfereeCredit::create([
            'student_id' => $student->id, 'source_institution' => 'TIP',
            'source_subject_code' => 'X1', 'source_subject_title' => 'Introduction to Computing',
            'credited_units' => 3, 'status' => TransfereeCreditStatus::Pending,
        ]);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($chair))
            ->getJson("/api/v1/transferee-credits/{$credit->id}/suggestions")
            ->assertOk();

        self::assertSame(['CS103'], array_column($response->json('data'), 'subject_code'));
    }

    public function test_only_the_owning_program_chair_may_ask_for_suggestions(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Coe, 'BSCE'));
        $credit = $this->makeCredit($student);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))
            ->getJson("/api/v1/transferee-credits/{$credit->id}/suggestions")
            ->assertForbidden();
    }

    public function test_registrar_staff_cannot_ask_for_suggestions(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student);
        $staff = $this->makeUser(UserRole::RegistrarStaff);

        $this->withToken($this->tokenFor($staff))
            ->getJson("/api/v1/transferee-credits/{$credit->id}/suggestions")
            ->assertForbidden();
    }

    public function test_a_student_cannot_ask_for_suggestions(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student);

        $this->withToken($this->tokenFor($student->user))
            ->getJson("/api/v1/transferee-credits/{$credit->id}/suggestions")
            ->assertForbidden();
    }

    // ------------------------------------------------------- drafts and submission

    public function test_saving_a_draft_notifies_nobody(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($student->user))
            ->postJson('/api/v1/transferee-credits', $this->studentRequestPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        self::assertSame(0, Notification::query()->count());
    }

    public function test_a_student_sees_their_own_drafts(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $this->makeCredit($student, null, TransfereeCreditStatus::Draft);

        $this->withToken($this->tokenFor($student->user))->getJson('/api/v1/transferee-credits')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'draft');
    }

    public function test_a_program_chair_never_sees_a_students_draft(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $this->makeCredit($student, null, TransfereeCreditStatus::Draft);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->getJson('/api/v1/transferee-credits')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_registrar_staff_never_see_a_students_draft(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $this->makeCredit($student, null, TransfereeCreditStatus::Draft);
        $staff = $this->makeUser(UserRole::RegistrarStaff);

        $this->withToken($this->tokenFor($staff))->getJson('/api/v1/transferee-credits')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_registrar_head_never_sees_a_students_draft(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $this->makeCredit($student, null, TransfereeCreditStatus::Draft);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($head))->getJson('/api/v1/transferee-credits')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_student_sends_every_saved_draft_to_the_program_chair_in_one_call(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $first = $this->makeCredit($student, null, TransfereeCreditStatus::Draft);
        $second = $this->makeCredit($student, null, TransfereeCreditStatus::Draft);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($student->user))
            ->postJson('/api/v1/transferee-credits/submit');

        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'pending');
        foreach ([$first, $second] as $credit) {
            $credit->refresh();
            self::assertSame(TransfereeCreditStatus::Pending, $credit->status);
            self::assertNotNull($credit->submitted_at);
        }
        $notification = Notification::query()->where('user_id', $chair->id)->sole();
        self::assertSame(NotificationType::TransfereeCreditRequested, $notification->type);
        self::assertStringContainsString('2 subjects', $notification->message);
        self::assertSame(2, AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_SUBMITTED)->count());
    }

    public function test_submitting_twice_changes_nothing_the_second_time(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $this->makeCredit($student, null, TransfereeCreditStatus::Draft);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->postJson('/api/v1/transferee-credits/submit')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->postJson('/api/v1/transferee-credits/submit')
            ->assertOk()->assertJsonCount(0, 'data');

        self::assertSame(1, Notification::query()->where('user_id', $chair->id)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_SUBMITTED)->count());
    }

    public function test_submitting_with_nothing_saved_is_an_empty_success(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($student->user))->postJson('/api/v1/transferee-credits/submit')
            ->assertOk()->assertJsonCount(0, 'data');

        self::assertSame(0, Notification::query()->where('user_id', $chair->id)->count());
    }

    public function test_submit_leaves_other_students_drafts_and_the_students_own_sent_rows_alone(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        $other = $this->makeStudent($curriculum);
        $mine = $this->makeCredit($student, null, TransfereeCreditStatus::Draft);
        $sent = $this->makeCredit($student, null, TransfereeCreditStatus::Endorsed);
        $theirs = $this->makeCredit($other, null, TransfereeCreditStatus::Draft);

        $this->withToken($this->tokenFor($student->user))->postJson('/api/v1/transferee-credits/submit')
            ->assertOk()->assertJsonCount(1, 'data');

        self::assertSame(TransfereeCreditStatus::Pending, $mine->refresh()->status);
        self::assertSame(TransfereeCreditStatus::Endorsed, $sent->refresh()->status);
        self::assertSame(TransfereeCreditStatus::Draft, $theirs->refresh()->status);
    }

    public function test_only_a_student_can_submit_a_list(): void
    {
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->postJson('/api/v1/transferee-credits/submit')
            ->assertForbidden();
    }

    public function test_a_program_chair_recorded_credit_is_pending_at_once_not_a_draft(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->postJson('/api/v1/transferee-credits', $this->studentRequestPayload([
            'student_id' => $student->id,
        ]))->assertCreated()->assertJsonPath('data.status', 'pending');
    }

    // ------------------------------------------------------ editing and removing drafts

    public function test_a_student_edits_their_own_draft(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student, null, TransfereeCreditStatus::Draft);

        $this->withToken($this->tokenFor($student->user))
            ->patchJson("/api/v1/transferee-credits/{$credit->id}", [
                'source_subject_title' => 'Edited Title',
                'credited_units' => 2.5,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.source_subject_title', 'Edited Title')
            ->assertJsonPath('data.credited_units', 2.5);

        self::assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_UPDATED)->count(),
        );
    }

    public function test_a_student_cannot_edit_a_credit_that_has_already_been_sent(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student);

        $this->withToken($this->tokenFor($student->user))
            ->patchJson("/api/v1/transferee-credits/{$credit->id}", ['source_subject_title' => 'Changed'])
            ->assertUnprocessable();

        self::assertSame('Introduction to Programming', $credit->refresh()->source_subject_title);
    }

    public function test_a_student_cannot_edit_another_students_draft(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        $other = $this->makeStudent($curriculum);
        $credit = $this->makeCredit($other, null, TransfereeCreditStatus::Draft);

        $this->withToken($this->tokenFor($student->user))
            ->patchJson("/api/v1/transferee-credits/{$credit->id}", ['source_subject_title' => 'Changed'])
            ->assertForbidden();
    }

    public function test_a_student_can_neither_map_a_draft_nor_take_a_workflow_step(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, null, TransfereeCreditStatus::Draft);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['subject_id' => $subject->id])
            ->assertUnprocessable();
        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'endorse'])
            ->assertUnprocessable();

        $credit->refresh();
        self::assertNull($credit->subject_id);
        self::assertSame(TransfereeCreditStatus::Draft, $credit->status);
    }

    public function test_a_program_chair_cannot_touch_a_students_draft(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $subject = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, $subject->id, TransfereeCreditStatus::Draft);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $token = $this->tokenFor($chair);

        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['credited_units' => 5])
            ->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/transferee-credits/{$credit->id}", ['action' => 'endorse'])
            ->assertForbidden();
        $this->withToken($token)->getJson("/api/v1/transferee-credits/{$credit->id}/suggestions")
            ->assertForbidden();

        self::assertSame(TransfereeCreditStatus::Draft, $credit->refresh()->status);
    }

    public function test_a_student_removes_their_own_draft(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student, null, TransfereeCreditStatus::Draft);

        $this->withToken($this->tokenFor($student->user))
            ->deleteJson("/api/v1/transferee-credits/{$credit->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('transferee_credits', ['id' => $credit->id]);
        self::assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_DELETED)->count(),
        );
    }

    public function test_a_student_cannot_remove_a_credit_that_has_already_been_sent(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student);

        $this->withToken($this->tokenFor($student->user))
            ->deleteJson("/api/v1/transferee-credits/{$credit->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('transferee_credits', ['id' => $credit->id]);
    }

    public function test_a_student_cannot_remove_another_students_draft(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        $other = $this->makeStudent($curriculum);
        $credit = $this->makeCredit($other, null, TransfereeCreditStatus::Draft);

        $this->withToken($this->tokenFor($student->user))
            ->deleteJson("/api/v1/transferee-credits/{$credit->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('transferee_credits', ['id' => $credit->id]);
    }

    public function test_staff_roles_cannot_remove_a_draft(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $credit = $this->makeCredit($student, null, TransfereeCreditStatus::Draft);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($head))
            ->deleteJson("/api/v1/transferee-credits/{$credit->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('transferee_credits', ['id' => $credit->id]);
    }
}

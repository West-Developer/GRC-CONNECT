<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Academic\TransfereeCreditStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\CollegeCode;
use App\Domain\Organization\ProgramStatus;
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
 * The three student-level credit mapping endpoints (ADR 0026, amended): the
 * Program Chair's bulk suggestions, "send this student to the Registrar Head",
 * and the Registrar Head's "approve all". Every test authenticates as exactly one
 * actor (the documented Sanctum gotcha), so state a second actor would have
 * produced is seeded through Eloquent.
 */
final class StudentCreditMappingEndpointTest extends TestCase
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
            'name' => 'Test Student '.$this->sequence, 'email' => "student.map{$this->sequence}@grc.test",
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
            'name' => 'Test '.$role->value, 'email' => "{$role->value}.map{$this->sequence}@grc.test",
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

    private function place(Curriculum $curriculum, Subject $subject, int $year = 1, string $semester = '1st'): void
    {
        CurriculumSubject::create([
            'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id,
            'year_level' => $year, 'semester' => $semester, 'is_required' => true,
        ]);
    }

    private function makeCredit(
        StudentProfile $student,
        string $title = 'Introduction to Programming',
        string $code = 'EXT101',
        ?int $subjectId = null,
        TransfereeCreditStatus $status = TransfereeCreditStatus::Pending,
    ): TransfereeCredit {
        return TransfereeCredit::create([
            'student_id' => $student->id,
            'source_institution' => 'Other University',
            'source_subject_code' => $code,
            'source_subject_title' => $title,
            'source_grade' => '1.75',
            'credited_units' => 3,
            'source_school_year' => '2023-2024',
            'source_semester' => '1st',
            'subject_id' => $subjectId,
            'status' => $status,
        ]);
    }

    private function suggestionsUrl(StudentProfile $student): string
    {
        return "/api/v1/student-profiles/{$student->id}/transferee-credits/suggestions";
    }

    // ------------------------------------------------------------- bulk suggestions

    public function test_the_chair_gets_suggestions_for_every_pending_credit_of_a_student_in_one_call(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $math = $this->makeSubject('MATH101', 'College Algebra');
        $eng = $this->makeSubject('ENG101', 'Purposive Communication');
        foreach ([$cs, $math, $eng] as $subject) {
            $this->place($curriculum, $subject);
        }
        $first = $this->makeCredit($student, 'Intro to Computing', 'CS 101');
        $second = $this->makeCredit($student, 'College Algebra', 'MAT 101');
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($chair))->getJson($this->suggestionsUrl($student))->assertOk();

        self::assertSame($student->id, $response->json('data.student_id'));
        self::assertSame([$first->id, $second->id], array_column($response->json('data.credits'), 'credit_id'));
        self::assertSame('CS101', $response->json('data.credits.0.suggestions.0.subject_code'));
        self::assertSame('MATH101', $response->json('data.credits.1.suggestions.0.subject_code'));
        self::assertSame(['CS101', 'ENG101', 'MATH101'], array_column($response->json('data.curriculum_subjects'), 'subject_code'));
        self::assertSame(
            ['subject_id', 'subject_code', 'subject_title', 'units', 'year_level', 'semester'],
            array_keys($response->json('data.curriculum_subjects.0')),
        );
    }

    public function test_bulk_suggestions_equal_the_single_credit_suggestions(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        foreach ([['CS101', 'Introduction to Computing'], ['CS105', 'Introduction to Computing Systems'], ['MATH101', 'College Algebra']] as [$code, $title]) {
            $this->place($curriculum, $this->makeSubject($code, $title));
        }
        $first = $this->makeCredit($student, 'Intro to Computing', 'CS 101');
        $second = $this->makeCredit($student, 'College Algebra', 'MAT 101');
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $token = $this->tokenFor($chair);

        $bulk = $this->withToken($token)->getJson($this->suggestionsUrl($student))->assertOk()->json('data.credits');

        foreach ([$first, $second] as $index => $credit) {
            $single = $this->withToken($token)->getJson("/api/v1/transferee-credits/{$credit->id}/suggestions")->assertOk()->json('data');
            self::assertSame($single, $bulk[$index]['suggestions']);
        }
    }

    public function test_the_bulk_suggestions_leave_out_what_another_credit_of_the_student_already_maps(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        $mapped = $this->makeSubject('CS101', 'Introduction to Computing');
        $this->place($curriculum, $mapped);
        $this->place($curriculum, $this->makeSubject('CS102', 'Introduction to Computing Lab'));
        $already = $this->makeCredit($student, 'Introduction to Computing', 'A1', $mapped->id);
        $other = $this->makeCredit($student, 'Introduction to Computing', 'B1');
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $credits = $this->withToken($this->tokenFor($chair))->getJson($this->suggestionsUrl($student))->assertOk()->json('data.credits');

        $byCredit = array_column($credits, 'suggestions', 'credit_id');
        // The credit that holds the mapping still sees its own subject; the other one does not.
        self::assertContains('CS101', array_column($byCredit[$already->id], 'subject_code'));
        self::assertNotContains('CS101', array_column($byCredit[$other->id], 'subject_code'));
    }

    public function test_the_bulk_suggestions_skip_drafts_and_decided_credits(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        $this->place($curriculum, $this->makeSubject('CS101', 'Introduction to Computing'));
        $pending = $this->makeCredit($student, 'Intro to Computing', 'A1');
        $this->makeCredit($student, 'Draft one', 'D1', null, TransfereeCreditStatus::Draft);
        $this->makeCredit($student, 'Endorsed one', 'E1', null, TransfereeCreditStatus::Endorsed);
        $this->makeCredit($student, 'Rejected one', 'R1', null, TransfereeCreditStatus::Rejected);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $credits = $this->withToken($this->tokenFor($chair))->getJson($this->suggestionsUrl($student))->assertOk()->json('data.credits');

        self::assertSame([$pending->id], array_column($credits, 'credit_id'));
    }

    public function test_a_student_with_no_pending_credit_gets_no_credits_but_the_curriculum(): void
    {
        $curriculum = $this->makeCurriculum(CollegeCode::Ccs);
        $student = $this->makeStudent($curriculum);
        $this->place($curriculum, $this->makeSubject('CS101', 'Introduction to Computing'));
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $response = $this->withToken($this->tokenFor($chair))->getJson($this->suggestionsUrl($student))->assertOk();

        self::assertSame([], $response->json('data.credits'));
        self::assertCount(1, $response->json('data.curriculum_subjects'));
    }

    public function test_a_chair_of_another_college_cannot_ask_for_suggestions(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Coe, 'BSCE'));
        $this->makeCredit($student);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->getJson($this->suggestionsUrl($student))->assertForbidden();
    }

    public function test_registrar_staff_cannot_ask_for_bulk_suggestions(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $staff = $this->makeUser(UserRole::RegistrarStaff);

        $this->withToken($this->tokenFor($staff))->getJson($this->suggestionsUrl($student))->assertForbidden();
    }

    public function test_the_registrar_head_cannot_ask_for_bulk_suggestions(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($head))->getJson($this->suggestionsUrl($student))->assertForbidden();
    }

    public function test_a_student_cannot_ask_for_bulk_suggestions(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));

        $this->withToken($this->tokenFor($student->user))->getJson($this->suggestionsUrl($student))->assertForbidden();
    }

    public function test_anonymous_requests_to_the_student_credit_endpoints_are_unauthenticated(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));

        $this->getJson($this->suggestionsUrl($student))->assertUnauthorized();
        $this->postJson($this->submitUrl($student))->assertUnauthorized();
    }

    // ------------------------------------------------- send a student to the Registrar Head

    private function submitUrl(StudentProfile $student): string
    {
        return "/api/v1/student-profiles/{$student->id}/transferee-credits/submit-to-registrar-head";
    }

    public function test_the_chair_sends_every_mapped_pending_credit_of_a_student_to_the_registrar_head(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $math = $this->makeSubject('MATH101', 'College Algebra');
        $first = $this->makeCredit($student, 'Intro to Computing', 'A1', $cs->id);
        $second = $this->makeCredit($student, 'College Algebra', 'B1', $math->id);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $head = $this->makeUser(UserRole::RegistrarHead);
        $staff = $this->makeUser(UserRole::RegistrarStaff);

        $response = $this->withToken($this->tokenFor($chair))->postJson($this->submitUrl($student))->assertOk();

        self::assertSame(['endorsed', 'endorsed'], array_column($response->json('data'), 'status'));
        foreach ([$first, $second] as $credit) {
            $credit->refresh();
            self::assertSame(TransfereeCreditStatus::Endorsed, $credit->status);
            self::assertSame($chair->id, $credit->endorsed_by);
            self::assertNotNull($credit->endorsed_at);
        }
        $notification = Notification::query()->where('user_id', $head->id)->sole();
        self::assertSame(NotificationType::TransfereeCreditEndorsed, $notification->type);
        self::assertStringContainsString('2 credits', $notification->message);
        self::assertSame(0, Notification::query()->where('user_id', $staff->id)->count());
        self::assertSame(2, AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_ENDORSED)->count());
    }

    public function test_it_sends_nothing_when_one_pending_credit_is_still_unmapped(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $mapped = $this->makeCredit($student, 'Intro to Computing', 'A1', $cs->id);
        $unmapped = $this->makeCredit($student, 'Statistics Elsewhere', 'B1');
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $response = $this->withToken($this->tokenFor($chair))->postJson($this->submitUrl($student))->assertUnprocessable();

        self::assertStringContainsString('Statistics Elsewhere', (string) $response->json('error.errors.credits.0'));
        self::assertSame(TransfereeCreditStatus::Pending, $mapped->refresh()->status);
        self::assertSame(TransfereeCreditStatus::Pending, $unmapped->refresh()->status);
        self::assertSame(0, Notification::query()->where('user_id', $head->id)->count());
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_ENDORSED)->count());
    }

    public function test_sending_twice_changes_nothing_the_second_time(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $this->makeCredit($student, 'Intro to Computing', 'A1', $cs->id);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $head = $this->makeUser(UserRole::RegistrarHead);
        $token = $this->tokenFor($chair);

        $this->withToken($token)->postJson($this->submitUrl($student))->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->postJson($this->submitUrl($student))->assertOk()->assertJsonCount(0, 'data');

        self::assertSame(1, Notification::query()->where('user_id', $head->id)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_ENDORSED)->count());
    }

    public function test_a_returned_credit_is_sent_again_and_its_return_details_clear(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $head = $this->makeUser(UserRole::RegistrarHead);
        $credit = $this->makeCredit($student, 'Intro to Computing', 'A1', $cs->id);
        $credit->forceFill([
            'returned_by' => $head->id, 'returned_at' => now(), 'return_reason' => 'Wrong subject.',
        ])->save();
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->postJson($this->submitUrl($student))->assertOk();

        $credit->refresh();
        self::assertSame(TransfereeCreditStatus::Endorsed, $credit->status);
        self::assertNull($credit->returned_by);
        self::assertNull($credit->returned_at);
        self::assertNull($credit->return_reason);
    }

    public function test_drafts_and_decided_credits_are_left_alone_when_a_student_is_sent(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $pending = $this->makeCredit($student, 'Intro to Computing', 'A1', $cs->id);
        $draft = $this->makeCredit($student, 'Draft', 'D1', null, TransfereeCreditStatus::Draft);
        $approved = $this->makeCredit($student, 'Approved', 'P1', $cs->id, TransfereeCreditStatus::Approved);
        $rejected = $this->makeCredit($student, 'Rejected', 'R1', null, TransfereeCreditStatus::Rejected);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->postJson($this->submitUrl($student))->assertOk()->assertJsonCount(1, 'data');

        self::assertSame(TransfereeCreditStatus::Endorsed, $pending->refresh()->status);
        self::assertSame(TransfereeCreditStatus::Draft, $draft->refresh()->status);
        self::assertSame(TransfereeCreditStatus::Approved, $approved->refresh()->status);
        self::assertSame(TransfereeCreditStatus::Rejected, $rejected->refresh()->status);
    }

    public function test_sending_a_student_with_nothing_pending_is_an_empty_success(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($chair))->postJson($this->submitUrl($student))->assertOk()->assertJsonCount(0, 'data');

        self::assertSame(0, Notification::query()->where('user_id', $head->id)->count());
    }

    public function test_a_chair_of_another_college_cannot_send_the_student(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Coe, 'BSCE'));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, 'Intro to Computing', 'A1', $cs->id);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->postJson($this->submitUrl($student))->assertForbidden();

        self::assertSame(TransfereeCreditStatus::Pending, $credit->refresh()->status);
    }

    public function test_registrar_staff_cannot_send_a_student_to_the_registrar_head(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $staff = $this->makeUser(UserRole::RegistrarStaff);

        $this->withToken($this->tokenFor($staff))->postJson($this->submitUrl($student))->assertForbidden();
    }

    public function test_the_registrar_head_cannot_send_a_student_to_himself(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($head))->postJson($this->submitUrl($student))->assertForbidden();
    }

    public function test_a_student_cannot_send_their_own_list_to_the_registrar_head(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));

        $this->withToken($this->tokenFor($student->user))->postJson($this->submitUrl($student))->assertForbidden();
    }

    // ------------------------------------------------------------------- approve all

    private function approveAllUrl(StudentProfile $student): string
    {
        return "/api/v1/student-profiles/{$student->id}/transferee-credits/approve-all";
    }

    public function test_the_registrar_head_approves_all_of_a_students_endorsed_credits(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $math = $this->makeSubject('MATH101', 'College Algebra');
        $first = $this->makeCredit($student, 'Intro to Computing', 'A1', $cs->id, TransfereeCreditStatus::Endorsed);
        $second = $this->makeCredit($student, 'College Algebra', 'B1', $math->id, TransfereeCreditStatus::Endorsed);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $response = $this->withToken($this->tokenFor($head))->postJson($this->approveAllUrl($student))->assertOk();

        self::assertSame(['approved', 'approved'], array_column($response->json('data'), 'status'));
        foreach ([$first, $second] as $credit) {
            $credit->refresh();
            self::assertSame(TransfereeCreditStatus::Approved, $credit->status);
            self::assertSame($head->id, $credit->processed_by);
            self::assertNotNull($credit->processed_at);
        }
        $notification = Notification::query()->where('user_id', $student->user_id)->sole();
        self::assertSame(NotificationType::TransfereeCreditApproved, $notification->type);
        self::assertStringContainsString('2', $notification->message);
        self::assertSame(2, AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_APPROVED)->count());
    }

    public function test_approve_all_touches_only_endorsed_rows(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $endorsed = $this->makeCredit($student, 'Endorsed', 'E1', $cs->id, TransfereeCreditStatus::Endorsed);
        $pending = $this->makeCredit($student, 'Pending', 'P1', $cs->id);
        $draft = $this->makeCredit($student, 'Draft', 'D1', null, TransfereeCreditStatus::Draft);
        $rejected = $this->makeCredit($student, 'Rejected', 'R1', null, TransfereeCreditStatus::Rejected);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($head))->postJson($this->approveAllUrl($student))->assertOk()->assertJsonCount(1, 'data');

        self::assertSame(TransfereeCreditStatus::Approved, $endorsed->refresh()->status);
        self::assertSame(TransfereeCreditStatus::Pending, $pending->refresh()->status);
        self::assertSame(TransfereeCreditStatus::Draft, $draft->refresh()->status);
        self::assertSame(TransfereeCreditStatus::Rejected, $rejected->refresh()->status);
    }

    public function test_approve_all_with_nothing_endorsed_is_an_empty_success(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $this->makeCredit($student);
        $head = $this->makeUser(UserRole::RegistrarHead);

        $this->withToken($this->tokenFor($head))->postJson($this->approveAllUrl($student))->assertOk()->assertJsonCount(0, 'data');

        self::assertSame(0, Notification::query()->where('user_id', $student->user_id)->count());
    }

    public function test_approving_all_twice_has_no_second_effect(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $this->makeCredit($student, 'Endorsed', 'E1', $cs->id, TransfereeCreditStatus::Endorsed);
        $head = $this->makeUser(UserRole::RegistrarHead);
        $token = $this->tokenFor($head);

        $this->withToken($token)->postJson($this->approveAllUrl($student))->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->postJson($this->approveAllUrl($student))->assertOk()->assertJsonCount(0, 'data');

        self::assertSame(1, Notification::query()->where('user_id', $student->user_id)->count());
        self::assertSame(1, AuditLog::query()->where('action', AuditAction::TRANSFEREE_CREDIT_APPROVED)->count());
    }

    public function test_a_program_chair_cannot_approve_all(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, 'Endorsed', 'E1', $cs->id, TransfereeCreditStatus::Endorsed);
        $chair = $this->makeUser(UserRole::ProgramChair, CollegeCode::Ccs);

        $this->withToken($this->tokenFor($chair))->postJson($this->approveAllUrl($student))->assertForbidden();

        self::assertSame(TransfereeCreditStatus::Endorsed, $credit->refresh()->status);
    }

    public function test_registrar_staff_cannot_approve_all(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, 'Endorsed', 'E1', $cs->id, TransfereeCreditStatus::Endorsed);
        $staff = $this->makeUser(UserRole::RegistrarStaff);

        $this->withToken($this->tokenFor($staff))->postJson($this->approveAllUrl($student))->assertForbidden();

        self::assertSame(TransfereeCreditStatus::Endorsed, $credit->refresh()->status);
    }

    public function test_a_student_cannot_approve_all_their_own_credits(): void
    {
        $student = $this->makeStudent($this->makeCurriculum(CollegeCode::Ccs));
        $cs = $this->makeSubject('CS101', 'Introduction to Computing');
        $credit = $this->makeCredit($student, 'Endorsed', 'E1', $cs->id, TransfereeCreditStatus::Endorsed);

        $this->withToken($this->tokenFor($student->user))->postJson($this->approveAllUrl($student))->assertForbidden();

        self::assertSame(TransfereeCreditStatus::Endorsed, $credit->refresh()->status);
    }
}

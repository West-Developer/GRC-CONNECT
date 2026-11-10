<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\EnrollmentSubjectStatus;
use App\Domain\Enrollment\WithdrawalStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Notifications\NotificationType;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Domain\Scheduling\SectionStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Notification;
use App\Models\Program;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WithdrawalRequestsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);

        return Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeStudent(Curriculum $curriculum, string $email = 'student.withdraw@grc.test', string $studentNumber = '2026-0001'): StudentProfile
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $studentNumber,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function tokenForNewUser(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    /**
     * Builds a real enrolled enrollment with one seat-occupying
     * EnrollmentSubject and a section whose enrolled_count reflects it —
     * the state a withdrawal request actually operates against.
     * `$subjectCode` must be unique per call within a single test (the
     * `subjects.code` column is unique).
     */
    private function makeEnrolledEnrollmentWithSeat(StudentProfile $student, AcademicTerm $term, string $subjectCode = 'CS101'): array
    {
        $subject = Subject::create(['code' => $subjectCode, 'title' => 'Programming 1', 'units' => 3.0, 'status' => SubjectStatus::Active]);
        $section = Section::create([
            'academic_term_id' => $term->id, 'subject_id' => $subject->id, 'section_code' => 'A',
            'capacity' => 40, 'enrolled_count' => 1, 'status' => SectionStatus::Published,
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled, 'total_units' => 3, 'submitted_at' => now(),
            'registrar_decided_at' => now(), 'payment_confirmed_at' => now(), 'enrolled_at' => now(),
        ]);
        $enrollmentSubject = EnrollmentSubject::create([
            'enrollment_id' => $enrollment->id, 'section_id' => $section->id,
            'status' => EnrollmentSubjectStatus::Enrolled,
        ]);

        return [$enrollment, $section, $enrollmentSubject];
    }

    /**
     * Seeds a pending withdrawal request directly via Eloquent rather than
     * through the student's own POST /withdraw endpoint. Chaining
     * `withToken()` for two *different* users within one test method
     * silently reuses the first user's cached guard resolution (the same
     * documented Sanctum test gotcha noted in EnrollmentsEndpointTest) — so
     * every test below that decides on a request (as Registrar Staff/Head)
     * authenticates as exactly one actor and seeds the pending request
     * directly instead of via a second login+submit.
     */
    private function makeWithdrawalRequest(Enrollment $enrollment, string $reason = 'Relocating.'): WithdrawalRequest
    {
        return WithdrawalRequest::create([
            'enrollment_id' => $enrollment->id,
            'reason' => $reason,
            'status' => WithdrawalStatus::Pending,
        ]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->postJson('/api/v1/enrollments/1/withdraw', [])->assertUnauthorized();
        $this->getJson('/api/v1/withdrawal-requests')->assertUnauthorized();
    }

    public function test_a_student_can_request_withdrawal_from_their_own_enrolled_enrollment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        [$enrollment] = $this->makeEnrolledEnrollmentWithSeat($student, $term);
        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/withdraw", [
            'reason' => 'Relocating to another institution.',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        self::assertSame(AuditAction::WITHDRAWAL_REQUEST_CREATED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
        self::assertSame('enrolled', $enrollment->refresh()->status->value);
    }

    public function test_a_withdrawal_request_notifies_registrar_staff_and_the_registrar_head(): void
    {
        $staff = User::create(['name' => 'Notify Staff', 'email' => 'registrar.staff.wd@grc.test', 'password' => 'correct-horse-battery-staple', 'role' => UserRole::RegistrarStaff, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $head = User::create(['name' => 'Notify Head', 'email' => 'registrar.head.wd@grc.test', 'password' => 'correct-horse-battery-staple', 'role' => UserRole::RegistrarHead, 'status' => UserStatus::Active, 'last_otp_verified_at' => now()]);
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        [$enrollment] = $this->makeEnrolledEnrollmentWithSeat($student, $term);

        $this->withToken($this->tokenFor($student->user))
            ->postJson("/api/v1/enrollments/{$enrollment->id}/withdraw", ['reason' => 'Relocating to another institution.'])
            ->assertCreated();

        foreach ([$staff, $head] as $registrar) {
            $notification = Notification::query()->where('user_id', $registrar->id)->sole();
            self::assertSame(NotificationType::WithdrawalRequestSubmitted, $notification->type);
            self::assertStringContainsString($student->student_number, $notification->message);
        }
    }

    public function test_a_student_cannot_withdraw_another_students_enrollment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $owner = $this->makeStudent($curriculum);
        [$enrollment] = $this->makeEnrolledEnrollmentWithSeat($owner, $term);
        $other = $this->makeStudent($curriculum, 'other.student.withdraw@grc.test', '2026-0002');
        $token = $this->tokenFor($other->user);

        $response = $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/withdraw", [
            'reason' => 'Not my enrollment.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('withdrawal_requests', 0);
    }

    public function test_withdrawal_requires_a_reason_and_an_enrolled_enrollment(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        [$enrollment] = $this->makeEnrolledEnrollmentWithSeat($student, $term);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/withdraw", [])
            ->assertUnprocessable();

        $enrollment->update(['status' => EnrollmentStatus::PendingPayment]);
        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/withdraw", [
            'reason' => 'Too soon.',
        ])->assertUnprocessable();
    }

    public function test_a_second_pending_withdrawal_request_is_rejected(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        [$enrollment] = $this->makeEnrolledEnrollmentWithSeat($student, $term);
        $token = $this->tokenFor($student->user);

        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/withdraw", [
            'reason' => 'First request.',
        ])->assertCreated();

        $this->withToken($token)->postJson("/api/v1/enrollments/{$enrollment->id}/withdraw", [
            'reason' => 'Second request.',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('withdrawal_requests', 1);
    }

    public function test_registrar_staff_approves_a_withdrawal_and_releases_the_seat(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        [$enrollment, $section, $enrollmentSubject] = $this->makeEnrolledEnrollmentWithSeat($student, $term);
        $withdrawalRequest = $this->makeWithdrawalRequest($enrollment);

        $registrarStaffToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar-staff.approve@grc.test');
        $response = $this->withToken($registrarStaffToken)->patchJson(
            "/api/v1/withdrawal-requests/{$withdrawalRequest->id}",
            ['action' => 'approve'],
        );

        $response->assertOk()->assertJsonPath('data.status', 'approved');
        self::assertSame('withdrawn', $enrollment->refresh()->status->value);
        self::assertNull($enrollment->active_academic_term_id);
        self::assertSame('dropped', $enrollmentSubject->refresh()->status->value);
        self::assertSame(0, $section->refresh()->enrolled_count);
        self::assertSame(
            AuditAction::WITHDRAWAL_REQUEST_APPROVED,
            AuditLog::query()->where('auditable_type', 'withdrawal_request')->sole()->action,
        );
        self::assertSame(NotificationType::WithdrawalRequestApproved, Notification::query()->sole()->type);
    }

    public function test_a_second_approval_attempt_does_not_double_release_the_seat(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        [$enrollment, $section] = $this->makeEnrolledEnrollmentWithSeat($student, $term);
        $withdrawalRequest = $this->makeWithdrawalRequest($enrollment);
        $registrarStaffToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar-staff.double@grc.test');

        $this->withToken($registrarStaffToken)->patchJson(
            "/api/v1/withdrawal-requests/{$withdrawalRequest->id}",
            ['action' => 'approve'],
        )->assertOk();

        $repeat = $this->withToken($registrarStaffToken)->patchJson(
            "/api/v1/withdrawal-requests/{$withdrawalRequest->id}",
            ['action' => 'approve'],
        );

        $repeat->assertUnprocessable();
        self::assertSame(0, $section->refresh()->enrolled_count);
    }

    public function test_registrar_staff_rejects_a_withdrawal_requiring_a_reason(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        [$enrollment, $section] = $this->makeEnrolledEnrollmentWithSeat($student, $term);
        $withdrawalRequest = $this->makeWithdrawalRequest($enrollment);
        $registrarStaffToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar-staff.reject@grc.test');

        $withoutReason = $this->withToken($registrarStaffToken)->patchJson(
            "/api/v1/withdrawal-requests/{$withdrawalRequest->id}",
            ['action' => 'reject'],
        );
        $withoutReason->assertUnprocessable();

        $response = $this->withToken($registrarStaffToken)->patchJson(
            "/api/v1/withdrawal-requests/{$withdrawalRequest->id}",
            ['action' => 'reject', 'reason' => 'Balance still outstanding.'],
        );

        $response->assertOk()->assertJsonPath('data.status', 'rejected');
        self::assertSame('enrolled', $enrollment->refresh()->status->value);
        self::assertSame(1, $section->refresh()->enrolled_count);
        self::assertSame(
            'Balance still outstanding.',
            AuditLog::query()->where('auditable_type', 'withdrawal_request')->sole()->reason,
        );
    }

    public function test_a_registrar_head_can_decide_a_withdrawal_request(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        [$enrollment, $section] = $this->makeEnrolledEnrollmentWithSeat($student, $term);
        $withdrawalRequest = $this->makeWithdrawalRequest($enrollment);
        $registrarHeadToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar-head.decide@grc.test');

        $response = $this->withToken($registrarHeadToken)->patchJson(
            "/api/v1/withdrawal-requests/{$withdrawalRequest->id}",
            ['action' => 'approve'],
        );

        $response->assertOk()->assertJsonPath('data.status', 'approved');
        self::assertSame('withdrawn', $enrollment->refresh()->status->value);
        self::assertSame(0, $section->refresh()->enrolled_count);
    }

    public function test_accounting_staff_cannot_decide_a_withdrawal_request(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        [$enrollment] = $this->makeEnrolledEnrollmentWithSeat($student, $term);
        $withdrawalRequest = $this->makeWithdrawalRequest($enrollment);
        $accountingToken = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.withdraw.forbidden@grc.test');

        $this->withToken($accountingToken)->patchJson(
            "/api/v1/withdrawal-requests/{$withdrawalRequest->id}",
            ['action' => 'approve'],
        )->assertForbidden();
    }

    public function test_a_student_sees_only_their_own_withdrawal_request(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum);
        [$enrollmentA] = $this->makeEnrolledEnrollmentWithSeat($studentA, $term, 'CS101');
        $this->makeWithdrawalRequest($enrollmentA, 'A relocating.');

        $studentB = $this->makeStudent($curriculum, 'student.b.withdraw@grc.test', '2026-0003');
        [$enrollmentB] = $this->makeEnrolledEnrollmentWithSeat($studentB, $term, 'CS102');
        $this->makeWithdrawalRequest($enrollmentB, 'B relocating.');

        $tokenA = $this->tokenFor($studentA->user);
        $studentAView = $this->withToken($tokenA)->getJson('/api/v1/withdrawal-requests');

        $studentAView->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_registrar_staff_sees_every_withdrawal_request(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum);
        [$enrollmentA] = $this->makeEnrolledEnrollmentWithSeat($studentA, $term, 'CS101');
        $this->makeWithdrawalRequest($enrollmentA, 'A relocating.');

        $studentB = $this->makeStudent($curriculum, 'student.b.withdraw@grc.test', '2026-0003');
        [$enrollmentB] = $this->makeEnrolledEnrollmentWithSeat($studentB, $term, 'CS102');
        $this->makeWithdrawalRequest($enrollmentB, 'B relocating.');

        $registrarStaffToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar-staff.viewall@grc.test');
        $staffView = $this->withToken($registrarStaffToken)->getJson('/api/v1/withdrawal-requests');

        $staffView->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_registrar_head_sees_every_withdrawal_request(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum);
        [$enrollmentA] = $this->makeEnrolledEnrollmentWithSeat($studentA, $term, 'CS101');
        $this->makeWithdrawalRequest($enrollmentA, 'A relocating.');

        $studentB = $this->makeStudent($curriculum, 'student.b.withdraw@grc.test', '2026-0003');
        [$enrollmentB] = $this->makeEnrolledEnrollmentWithSeat($studentB, $term, 'CS102');
        $this->makeWithdrawalRequest($enrollmentB, 'B relocating.');

        $registrarHeadToken = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar-head.viewall@grc.test');
        $headView = $this->withToken($registrarHeadToken)->getJson('/api/v1/withdrawal-requests');

        $headView->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_no_seat_is_released_when_the_config_flag_is_off(): void
    {
        config(['enrollment.withdrawal.releases_seats' => false]);
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        [$enrollment, $section, $enrollmentSubject] = $this->makeEnrolledEnrollmentWithSeat($student, $term);
        $withdrawalRequest = $this->makeWithdrawalRequest($enrollment);
        $registrarStaffToken = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar-staff.noflag@grc.test');

        $this->withToken($registrarStaffToken)->patchJson(
            "/api/v1/withdrawal-requests/{$withdrawalRequest->id}",
            ['action' => 'approve'],
        )->assertOk();

        self::assertSame('dropped', $enrollmentSubject->refresh()->status->value);
        self::assertSame(1, $section->refresh()->enrolled_count);
    }
}

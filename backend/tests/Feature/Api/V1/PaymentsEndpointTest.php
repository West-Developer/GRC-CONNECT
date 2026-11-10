<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentsEndpointTest extends TestCase
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

    private function makeStudent(Curriculum $curriculum, string $email = 'student.payments@grc.test', string $studentNumber = '2026-0001'): StudentProfile
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

    private function makePayment(StudentProfile $student, AcademicTerm $term, string $confirmedAt = '2026-08-01 09:00:00'): Payment
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled, 'total_units' => 3,
        ]);
        $accountant = User::query()->where('role', UserRole::AccountingStaff)->first()
            ?? User::create([
                'name' => 'Test Accounting', 'email' => 'accounting.payments.setup@grc.test',
                'password' => self::PASSWORD, 'role' => UserRole::AccountingStaff, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
            ]);

        return Payment::create([
            'enrollment_id' => $enrollment->id,
            'confirmed_by' => $accountant->id,
            'external_reference' => 'OR-1',
            'amount' => '2400.00',
            'confirmed_at' => $confirmedAt,
        ]);
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

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/payments')->assertUnauthorized();
    }

    public function test_a_student_role_is_forbidden(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $student->user->email, 'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/payments')->assertForbidden();
    }

    public function test_accounting_staff_sees_the_payment_history(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $payment = $this->makePayment($student, $term);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.history@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/payments');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $payment->id);
        $response->assertJsonPath('data.0.student_number', $student->student_number);
        $response->assertJsonPath('data.0.amount', '2400.00');
        self::assertSame(
            ['type', 'id', 'enrollment_id', 'student_number', 'external_reference', 'amount', 'confirmed_at'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_registrar_head_also_sees_the_payment_history(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $this->makePayment($student, $term);
        $token = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar.head.history@grc.test');

        $this->withToken($token)->getJson('/api/v1/payments')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_registrar_staff_is_forbidden(): void
    {
        $token = $this->tokenForNewUser(UserRole::RegistrarStaff, 'registrar.staff.history@grc.test');

        $this->withToken($token)->getJson('/api/v1/payments')->assertForbidden();
    }

    public function test_payments_survive_the_enrollment_leaving_pending_payment(): void
    {
        // The whole reason this endpoint reads `payments` directly instead
        // of widening Enrollment::scopeVisibleTo: once ConfirmPayment moves
        // the enrollment to `enrolled`, it drops out of Accounting's
        // pending_payment enrollment view — but the payment record itself
        // must remain visible here.
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $this->makePayment($student, $term);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.survives@grc.test');

        $this->withToken($token)->getJson('/api/v1/payments')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_can_filter_by_confirmed_on_date(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'first.confirmed@grc.test', '2026-0001');
        $studentB = $this->makeStudent($curriculum, 'second.confirmed@grc.test', '2026-0002');
        $this->makePayment($studentA, $term, '2026-08-01 09:00:00');
        $onTarget = $this->makePayment($studentB, $term, '2026-08-02 09:00:00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.filterdate@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/payments?confirmed_on=2026-08-02');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $onTarget->id);
    }

    public function test_payments_are_ordered_most_recently_confirmed_first(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'earlier.confirmed@grc.test', '2026-0001');
        $studentB = $this->makeStudent($curriculum, 'later.confirmed@grc.test', '2026-0002');
        $earlier = $this->makePayment($studentA, $term, '2026-08-01 09:00:00');
        $later = $this->makePayment($studentB, $term, '2026-08-02 09:00:00');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.orderrecent@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/payments');

        $response->assertJsonPath('data.0.id', $later->id);
        $response->assertJsonPath('data.1.id', $earlier->id);
    }
}

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
use App\Models\AccountPayment;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CashierTransactionsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/cashier-transactions')->assertUnauthorized();
    }

    public function test_student_and_registrar_staff_cannot_read_cashier_transactions(): void
    {
        $studentToken = $this->tokenFor(UserRole::Student, 'student.transactions@grc.test');
        $registrarStaffToken = $this->tokenFor(UserRole::RegistrarStaff, 'registrar.transactions@grc.test');

        $this->withToken($studentToken)->getJson('/api/v1/cashier-transactions')->assertForbidden();
        $this->withToken($registrarStaffToken)->getJson('/api/v1/cashier-transactions')->assertForbidden();
    }

    public function test_accounting_staff_sees_normalized_enrollment_and_balance_transactions_newest_first(): void
    {
        [$student, $enrollmentPayment, $accountPayment] = $this->makeTransactionHistory();
        $token = $this->tokenFor(UserRole::AccountingStaff, 'accounting.transactions@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/cashier-transactions');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.0.id', 'account_payment:'.$accountPayment->id)
            ->assertJsonPath('data.0.transaction_type', 'account_payment')
            ->assertJsonPath('data.0.student_id', $student->id)
            ->assertJsonPath('data.0.student_name', $student->user->name)
            ->assertJsonPath('data.0.student_number', $student->student_number)
            ->assertJsonPath('data.0.enrollment_id', $accountPayment->enrollment_id)
            ->assertJsonPath('data.0.amount', '500.00')
            ->assertJsonPath('data.1.id', 'enrollment_payment:'.$enrollmentPayment->id)
            ->assertJsonPath('data.1.transaction_type', 'enrollment_payment')
            ->assertJsonPath('data.1.amount', '2400.00');

        self::assertSame(
            [
                'type',
                'id',
                'transaction_type',
                'student_id',
                'student_name',
                'student_number',
                'enrollment_id',
                'amount',
                'processed_at',
            ],
            array_keys($response->json('data.0')),
        );
    }

    public function test_transaction_history_filters_by_exact_student_number_and_processed_date(): void
    {
        [$target, , $targetAccountPayment] = $this->makeTransactionHistory(
            studentNumber: '2026-06-01001',
            enrollmentConfirmedAt: '2026-08-01 09:00:00',
            accountReceivedAt: '2026-08-02 09:00:00',
        );
        $this->makeTransactionHistory(
            studentNumber: '2026-06-01002',
            enrollmentConfirmedAt: '2026-08-02 10:00:00',
            accountReceivedAt: '2026-08-03 09:00:00',
        );
        $token = $this->tokenFor(UserRole::AccountingStaff, 'accounting.filter.transactions@grc.test');

        $response = $this->withToken($token)->getJson(
            '/api/v1/cashier-transactions?student_number='.$target->student_number.'&processed_on=2026-08-02',
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'account_payment:'.$targetAccountPayment->id)
            ->assertJsonPath('data.0.student_number', $target->student_number);
    }

    public function test_registrar_head_can_read_cashier_transactions(): void
    {
        $this->makeTransactionHistory();
        $token = $this->tokenFor(UserRole::RegistrarHead, 'registrar.head.transactions@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/cashier-transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * @return array{StudentProfile, Payment, AccountPayment}
     */
    private function makeTransactionHistory(
        string $studentNumber = '2026-06-01001',
        string $enrollmentConfirmedAt = '2026-08-01 09:00:00',
        string $accountReceivedAt = '2026-08-02 09:00:00',
    ): array {
        $term = AcademicTerm::query()->firstOrCreate([
            'school_year' => '2026-2027',
            'semester' => '1st',
        ], [
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create([
            'code' => 'BSCS'.str_replace('-', '', $studentNumber),
            'name' => 'BS Computer Science',
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Active,
        ]);
        $studentUser = User::create([
            'name' => 'Student '.$studentNumber,
            'email' => 'student.'.$studentNumber.'@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
        $student = StudentProfile::create([
            'user_id' => $studentUser->id,
            'student_number' => $studentNumber,
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled,
            'total_units' => 3,
        ]);
        $cashier = User::create([
            'name' => 'Cashier '.$studentNumber,
            'email' => 'cashier.'.$studentNumber.'@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::AccountingStaff,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
        $payment = Payment::create([
            'enrollment_id' => $enrollment->id,
            'confirmed_by' => $cashier->id,
            'amount' => '2400.00',
            'confirmed_at' => $enrollmentConfirmedAt,
        ]);
        $accountPayment = AccountPayment::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'received_by' => $cashier->id,
            'amount' => '500.00',
            'received_at' => $accountReceivedAt,
        ]);

        return [$student->load('user'), $payment, $accountPayment];
    }

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
}

<?php

namespace Tests\Unit\Actions\Billing;

use App\Actions\Billing\BuildStudentAccountBalance;
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
use App\Models\Assessment;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BuildStudentAccountBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeStudent(): StudentProfile
    {
        $program = Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS Curriculum',
            'effective_school_year' => '2025-2026',
            'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Account Student',
            'email' => 'account.balance@student.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => '2025-0001',
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 2,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    private function makeTerm(string $schoolYear, string $semester, string $startsAt): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => $schoolYear,
            'semester' => $semester,
            'starts_at' => $startsAt,
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function assessedEnrollment(
        StudentProfile $student,
        AcademicTerm $term,
        string $assessmentAmount,
        EnrollmentStatus $status = EnrollmentStatus::Enrolled,
    ): Enrollment {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => $status,
            'total_units' => 3,
            'submitted_at' => now(),
        ]);
        Assessment::create([
            'enrollment_id' => $enrollment->id,
            'total_amount' => $assessmentAmount,
            'currency' => 'PHP',
            'assessed_at' => now(),
        ]);

        return $enrollment;
    }

    private function cashier(): User
    {
        return User::create([
            'name' => 'Cashier',
            'email' => 'cashier.account-balance@test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::AccountingStaff,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_it_calculates_current_and_prior_balances_with_exact_payment_totals(): void
    {
        $student = $this->makeStudent();
        $prior = $this->assessedEnrollment(
            $student,
            $this->makeTerm('2025-2026', '2nd', '2026-01-05 00:00:00'),
            '5000.00',
        );
        $this->assessedEnrollment(
            $student,
            $this->makeTerm('2026-2027', '1st', '2026-08-05 00:00:00'),
            '1500.00',
        );
        $cashier = $this->cashier();
        Payment::create([
            'enrollment_id' => $prior->id,
            'confirmed_by' => $cashier->id,
            'amount' => '1000.00',
            'promissory_note_on_file' => true,
            'confirmed_at' => now(),
        ]);
        AccountPayment::create([
            'student_id' => $student->id,
            'enrollment_id' => $prior->id,
            'received_by' => $cashier->id,
            'amount' => '500.00',
            'received_at' => now(),
        ]);

        $balance = app(BuildStudentAccountBalance::class)->execute($student);

        self::assertSame('6500.00', $balance->totalAssessed);
        self::assertSame('1500.00', $balance->totalPaid);
        self::assertSame('5000.00', $balance->outstandingBalance);
        self::assertSame('3500.00', $balance->priorBalance);
        self::assertTrue($balance->hasPromissoryNoteOnFile);
        self::assertCount(2, $balance->entries);
        self::assertSame($prior->id, $balance->entries[0]->enrollmentId);
        self::assertSame('3500.00', $balance->entries[0]->outstandingBalance);
    }

    public function test_it_excludes_cancelled_rejected_and_withdrawn_assessments(): void
    {
        $student = $this->makeStudent();
        foreach ([EnrollmentStatus::Cancelled, EnrollmentStatus::Rejected, EnrollmentStatus::Withdrawn] as $index => $status) {
            $this->assessedEnrollment(
                $student,
                $this->makeTerm("202{$index}-202".($index + 1), '1st', "202{$index}-06-01 00:00:00"),
                '1000.00',
                $status,
            );
        }

        $balance = app(BuildStudentAccountBalance::class)->execute($student);

        self::assertSame('0.00', $balance->totalAssessed);
        self::assertSame('0.00', $balance->totalPaid);
        self::assertSame('0.00', $balance->outstandingBalance);
        self::assertSame('0.00', $balance->priorBalance);
        self::assertFalse($balance->hasPromissoryNoteOnFile);
        self::assertSame([], $balance->entries);
    }

    public function test_it_calculates_advance_payment_balance_when_payments_exceed_assessment(): void
    {
        $student = $this->makeStudent();
        $enrollment = $this->assessedEnrollment(
            $student,
            $this->makeTerm('2025-2026', '2nd', '2026-01-05 00:00:00'),
            '1000.00',
        );
        $cashier = $this->cashier();
        Payment::create([
            'enrollment_id' => $enrollment->id,
            'confirmed_by' => $cashier->id,
            'amount' => '1000.00',
            'promissory_note_on_file' => false,
            'confirmed_at' => now(),
        ]);
        AccountPayment::create([
            'student_id' => $student->id,
            'enrollment_id' => null,
            'received_by' => $cashier->id,
            'amount' => '500.00',
            'received_at' => now()->addMinute(),
        ]);

        $balance = app(BuildStudentAccountBalance::class)->execute($student);

        self::assertSame('1000.00', $balance->totalAssessed);
        self::assertSame('1500.00', $balance->totalPaid);
        self::assertSame('0.00', $balance->outstandingBalance);
        self::assertSame('500.00', $balance->advancePaymentBalance);
        self::assertSame([], $balance->entries);
        self::assertCount(2, $balance->transactions);
        self::assertSame('Advance Payment / Credit', $balance->transactions[0]['transaction_type_label']);
    }

    public function test_unallocated_advance_payment_without_enrollment_shows_as_credit(): void
    {
        $student = $this->makeStudent();
        $cashier = $this->cashier();
        AccountPayment::create([
            'student_id' => $student->id,
            'enrollment_id' => null,
            'received_by' => $cashier->id,
            'amount' => '750.00',
            'received_at' => now(),
        ]);

        $balance = app(BuildStudentAccountBalance::class)->execute($student);

        self::assertSame('0.00', $balance->totalAssessed);
        self::assertSame('750.00', $balance->totalPaid);
        self::assertSame('0.00', $balance->outstandingBalance);
        self::assertSame('750.00', $balance->advancePaymentBalance);
        self::assertSame([], $balance->entries);
        self::assertCount(1, $balance->transactions);
    }
}

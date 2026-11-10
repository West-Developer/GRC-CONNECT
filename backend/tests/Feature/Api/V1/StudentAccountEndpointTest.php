<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudentAccountEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeCurriculum(): Curriculum
    {
        $program = Program::create([
            'code' => 'BSCS',
            'name' => 'BS Computer Science',
            'status' => ProgramStatus::Active,
        ]);

        return Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSCS Curriculum',
            'effective_school_year' => '2025-2026',
            'status' => CurriculumStatus::Active,
        ]);
    }

    private function makeStudent(Curriculum $curriculum, string $email, string $studentNumber): StudentProfile
    {
        $user = User::create([
            'name' => "Student {$studentNumber}",
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $studentNumber,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 3,
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

    private function assessedEnrollment(StudentProfile $student, AcademicTerm $term, string $amount): Enrollment
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::Enrolled,
            'total_units' => 3,
            'submitted_at' => now(),
        ]);
        Assessment::create([
            'enrollment_id' => $enrollment->id,
            'total_amount' => $amount,
            'currency' => 'PHP',
            'assessed_at' => now(),
        ]);

        return $enrollment;
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function accountingToken(): string
    {
        $accounting = User::create([
            'name' => 'Cashier',
            'email' => 'cashier.student-account@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::AccountingStaff,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return $this->tokenFor($accounting);
    }

    public function test_a_student_can_read_only_their_own_account_summary(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.account@grc.test', '2026-0001');
        $otherStudent = $this->makeStudent($curriculum, 'student.other-account@grc.test', '2026-0002');
        $this->assessedEnrollment(
            $student,
            $this->makeTerm('2025-2026', '2nd', '2026-01-05 00:00:00'),
            '3500.00',
        );

        $token = $this->tokenFor($student->user);

        $response = $this->withToken($token)->getJson('/api/v1/student-account');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.student_number', '2026-0001')
            ->assertJsonPath('data.outstanding_balance', '3500.00');
        $this->withToken($token)
            ->getJson("/api/v1/students/{$otherStudent->id}/account")
            ->assertForbidden();
    }

    public function test_an_accounting_payment_is_allocated_to_the_oldest_outstanding_enrollment_without_changing_the_queue_ticket(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.allocation@grc.test', '2026-0001');
        $oldest = $this->assessedEnrollment(
            $student,
            $this->makeTerm('2025-2026', '2nd', '2026-01-05 00:00:00'),
            '1000.00',
        );
        $newer = $this->assessedEnrollment(
            $student,
            $this->makeTerm('2026-2027', '1st', '2026-08-05 00:00:00'),
            '2000.00',
        );
        $cycle = QueueCycle::create(['opened_on' => '2026-08-14', 'last_ticket_sequence' => 1]);
        $ticket = QueueTicket::create([
            'enrollment_id' => $newer->id,
            'queue_cycle_id' => $cycle->id,
            'ticket_sequence' => 1,
            'ticket_number' => 'Q001',
            'queue_date' => '2026-08-14',
            'status' => QueueTicketStatus::Waiting,
        ]);

        $response = $this->withToken($this->accountingToken())
            ->postJson("/api/v1/students/{$student->id}/account-payments", ['amount' => 500]);

        $response->assertCreated()
            ->assertJsonPath('data.outstanding_balance', '2500.00')
            ->assertJsonPath('data.prior_balance', '500.00');
        $this->assertDatabaseHas('account_payments', [
            'student_id' => $student->id,
            'enrollment_id' => $oldest->id,
            'amount' => '500.00',
        ]);
        $this->assertDatabaseMissing('account_payments', ['enrollment_id' => $newer->id]);
        self::assertSame(QueueTicketStatus::Waiting, $ticket->refresh()->status);
        self::assertNull($ticket->refresh()->requeued_at);
        self::assertSame(AuditAction::ACCOUNT_PAYMENT_RECORDED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_an_accounting_payment_spans_enrollments_in_oldest_first_order_when_needed(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.split-allocation@grc.test', '2026-0003');
        $oldest = $this->assessedEnrollment(
            $student,
            $this->makeTerm('2025-2026', '2nd', '2026-01-05 00:00:00'),
            '500.00',
        );
        $newer = $this->assessedEnrollment(
            $student,
            $this->makeTerm('2026-2027', '1st', '2026-08-05 00:00:00'),
            '2000.00',
        );

        $response = $this->withToken($this->accountingToken())
            ->postJson("/api/v1/students/{$student->id}/account-payments", ['amount' => 1000]);

        $response->assertCreated()
            ->assertJsonPath('data.outstanding_balance', '1500.00')
            ->assertJsonPath('data.prior_balance', '0.00');
        $this->assertDatabaseHas('account_payments', [
            'student_id' => $student->id,
            'enrollment_id' => $oldest->id,
            'amount' => '500.00',
        ]);
        $this->assertDatabaseHas('account_payments', [
            'student_id' => $student->id,
            'enrollment_id' => $newer->id,
            'amount' => '500.00',
        ]);
        $this->assertDatabaseCount('account_payments', 2);
    }

    public function test_an_account_payment_exceeding_outstanding_balance_is_recorded_as_advance_payment(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.overpayment@grc.test', '2026-0001');
        $this->assessedEnrollment(
            $student,
            $this->makeTerm('2025-2026', '2nd', '2026-01-05 00:00:00'),
            '500.00',
        );

        $response = $this->withToken($this->accountingToken())
            ->postJson("/api/v1/students/{$student->id}/account-payments", ['amount' => 700.00]);

        $response->assertCreated()
            ->assertJsonPath('data.outstanding_balance', '0.00')
            ->assertJsonPath('data.advance_payment_balance', '200.00');
        $this->assertDatabaseCount('account_payments', 2);
    }

    public function test_an_account_payment_can_be_recorded_when_student_has_no_outstanding_balance(): void
    {
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'student.advance@grc.test', '2026-0002');

        $response = $this->withToken($this->accountingToken())
            ->postJson("/api/v1/students/{$student->id}/account-payments", ['amount' => 1000.00]);

        $response->assertCreated()
            ->assertJsonPath('data.outstanding_balance', '0.00')
            ->assertJsonPath('data.advance_payment_balance', '1000.00');
        $this->assertDatabaseHas('account_payments', [
            'student_id' => $student->id,
            'enrollment_id' => null,
            'amount' => '1000.00',
        ]);
    }
}

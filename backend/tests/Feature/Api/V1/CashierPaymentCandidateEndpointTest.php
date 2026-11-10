<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\QueueTicketPriority;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\Payment;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CashierPaymentCandidateEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-14 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_accounting_staff_finds_an_exact_waiting_candidate_without_changing_any_record(): void
    {
        [$student, $enrollment, $ticket] = $this->makeCandidate();
        $token = $this->tokenFor(UserRole::AccountingStaff, 'accounting.lookup@grc.test');
        $before = [
            'payments' => Payment::query()->count(),
            'documents' => EnrollmentDocument::query()->count(),
            'audits' => AuditLog::query()->count(),
            'enrollment_status' => $enrollment->status->value,
            'ticket_status' => $ticket->status->value,
        ];

        $response = $this->withToken($token)->getJson(
            '/api/v1/cashier-payment-candidates?student_number='.$student->student_number,
        );

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.type', 'cashier_payment_candidate')
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.student_name', $student->user->name)
            ->assertJsonPath('data.student_number', $student->student_number)
            ->assertJsonPath('data.year_level', $student->year_level)
            ->assertJsonPath('data.enrollment_id', $enrollment->id)
            ->assertJsonPath('data.ticket.id', $ticket->id)
            ->assertJsonPath('data.ticket.status', QueueTicketStatus::Waiting->value);

        self::assertSame($before['payments'], Payment::query()->count());
        self::assertSame($before['documents'], EnrollmentDocument::query()->count());
        self::assertSame($before['audits'], AuditLog::query()->count());
        self::assertSame($before['enrollment_status'], $enrollment->fresh()->status->value);
        self::assertSame($before['ticket_status'], $ticket->fresh()->status->value);
    }

    public function test_accounting_staff_can_find_the_matching_ticket_when_it_is_already_serving(): void
    {
        [$student, $enrollment, $ticket] = $this->makeCandidate(status: QueueTicketStatus::Serving);
        $token = $this->tokenFor(UserRole::AccountingStaff, 'accounting.serving.lookup@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/cashier-payment-candidates?student_number='.$student->student_number)
            ->assertOk()
            ->assertJsonPath('data.enrollment_id', $enrollment->id)
            ->assertJsonPath('data.ticket.id', $ticket->id)
            ->assertJsonPath('data.ticket.status', QueueTicketStatus::Serving->value);
    }

    public function test_lookup_hides_missing_non_pending_and_completed_records(): void
    {
        $missingToken = $this->tokenFor(UserRole::AccountingStaff, 'accounting.missing.lookup@grc.test');
        $this->withToken($missingToken)
            ->getJson('/api/v1/cashier-payment-candidates?student_number=2026-06-09999')
            ->assertNotFound();

        [$completedStudent] = $this->makeCandidate(
            studentNumber: '2026-06-01002',
            enrollmentStatus: EnrollmentStatus::Enrolled,
        );
        [$servedStudent] = $this->makeCandidate(
            studentNumber: '2026-06-01003',
            status: QueueTicketStatus::Served,
        );

        foreach ([$completedStudent, $servedStudent] as $student) {
            $this->withToken($missingToken)
                ->getJson('/api/v1/cashier-payment-candidates?student_number='.$student->student_number)
                ->assertNotFound();
        }
    }

    public function test_lookup_finds_a_carried_over_ticket_from_an_earlier_date(): void
    {
        [$student, $enrollment, $ticket] = $this->makeCandidate(
            studentNumber: '2026-06-01005',
            queueDate: '2026-08-12', // three days before the frozen "today" (2026-08-14)
        );
        $token = $this->tokenFor(UserRole::AccountingStaff, 'accounting.carrylookup@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/cashier-payment-candidates?student_number='.$student->student_number)
            ->assertOk()
            ->assertJsonPath('data.enrollment_id', $enrollment->id)
            ->assertJsonPath('data.ticket.id', $ticket->id);
    }

    public function test_lookup_finds_a_pending_payment_student_with_no_ticket_yet(): void
    {
        [$student, $enrollment] = $this->makeCandidate(studentNumber: '2026-06-01006', withTicket: false);
        $token = $this->tokenFor(UserRole::AccountingStaff, 'accounting.noticketlookup@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/cashier-payment-candidates?student_number='.$student->student_number)
            ->assertOk()
            ->assertJsonPath('data.enrollment_id', $enrollment->id)
            ->assertJsonPath('data.ticket', null);
    }

    public function test_lookup_requires_a_student_number(): void
    {
        $accountingToken = $this->tokenFor(UserRole::AccountingStaff, 'accounting.validation.lookup@grc.test');

        $this->withToken($accountingToken)
            ->getJson('/api/v1/cashier-payment-candidates')
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.errors.student_number.0',
                'The student number field is required.',
            );
    }

    public function test_student_cannot_look_up_an_eligible_cashier_candidate(): void
    {
        [$candidate] = $this->makeCandidate();
        $studentToken = $this->tokenFor(UserRole::Student, 'student.lookup@grc.test');

        $this->withToken($studentToken)
            ->getJson('/api/v1/cashier-payment-candidates?student_number='.$candidate->student_number)
            ->assertForbidden();
    }

    public function test_registrar_head_cannot_look_up_an_eligible_cashier_candidate(): void
    {
        [$candidate] = $this->makeCandidate();
        $headToken = $this->tokenFor(UserRole::RegistrarHead, 'head.lookup@grc.test');

        $this->withToken($headToken)
            ->getJson('/api/v1/cashier-payment-candidates?student_number='.$candidate->student_number)
            ->assertForbidden();
    }

    public function test_accounting_staff_finds_candidate_by_student_email_and_name(): void
    {
        [$student, $enrollment, $ticket] = $this->makeCandidate('2026-06-01099');
        $token = $this->tokenFor(UserRole::AccountingStaff, 'accounting.multisearch@grc.test');

        // Search by exact email
        $responseByEmail = $this->withToken($token)->getJson(
            '/api/v1/cashier-payment-candidates?student_number='.$student->user->email,
        );
        $responseByEmail->assertOk()
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.enrollment_id', $enrollment->id);

        // Search by student name
        $responseByName = $this->withToken($token)->getJson(
            '/api/v1/cashier-payment-candidates?student_number='.$student->user->name,
        );
        $responseByName->assertOk()
            ->assertJsonPath('data.student_id', $student->id)
            ->assertJsonPath('data.enrollment_id', $enrollment->id);
    }

    /**
     * @return array{StudentProfile, Enrollment, ?QueueTicket}
     */
    private function makeCandidate(
        string $studentNumber = '2026-06-01001',
        EnrollmentStatus $enrollmentStatus = EnrollmentStatus::PendingPayment,
        QueueTicketStatus $status = QueueTicketStatus::Waiting,
        string $queueDate = '2026-08-14',
        bool $withTicket = true,
    ): array {
        $term = AcademicTerm::query()->firstOrCreate([
            'school_year' => '2026-2027',
            'semester' => '1st',
        ], [
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
        $program = Program::create([
            'code' => 'BSIT'.str_replace('-', '', $studentNumber),
            'name' => 'BS Information Technology',
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'BSIT Curriculum',
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
            'year_level' => 2,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => $enrollmentStatus,
            'total_units' => 6,
        ]);

        $ticket = null;
        if ($withTicket) {
            $cycle = QueueCycle::query()->whereNull('closed_at')->first()
                ?? QueueCycle::create(['opened_on' => $queueDate, 'last_ticket_sequence' => 0]);
            $sequence = $cycle->last_ticket_sequence + 1;
            $cycle->update(['last_ticket_sequence' => $sequence, 'last_claimed_on' => $queueDate]);
            $ticket = QueueTicket::create([
                'enrollment_id' => $enrollment->id,
                'queue_cycle_id' => $cycle->id,
                'ticket_sequence' => $sequence,
                'ticket_number' => 'Q'.str_pad((string) $student->id, 3, '0', STR_PAD_LEFT),
                'queue_date' => $queueDate,
                'status' => $status,
                'priority' => QueueTicketPriority::Regular,
                'served_at' => $status === QueueTicketStatus::Served ? now() : null,
            ]);
        }

        return [$student->load('user'), $enrollment, $ticket];
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

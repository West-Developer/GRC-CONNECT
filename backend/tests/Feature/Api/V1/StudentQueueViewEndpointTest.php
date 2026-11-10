<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\QueueServiceDate;
use App\Domain\Enrollment\QueueTicketPriority;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StudentQueueViewEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/queue-status')->assertUnauthorized();
    }

    public function test_a_non_student_role_is_forbidden(): void
    {
        $token = $this->tokenFor(UserRole::AccountingStaff, 'accounting.viewstatus@grc.test');

        $this->withToken($token)->getJson('/api/v1/queue-status')->assertForbidden();
    }

    public function test_a_student_with_no_enrollment_this_term_sees_no_active_enrollment(): void
    {
        $this->makeTerm();
        $token = $this->makeStudentToken('2026-08-90001');

        $this->withToken($token)->getJson('/api/v1/queue-status')
            ->assertOk()
            ->assertJsonPath('data.stage', 'no_active_enrollment')
            ->assertJsonPath('data.can_claim', false)
            ->assertJsonPath('data.ticket', null);
    }

    public function test_a_student_awaiting_registrar_approval_cannot_claim_yet(): void
    {
        [$token] = $this->makeStudentWithEnrollment('2026-08-90002', EnrollmentStatus::PendingRegistrarApproval);

        $this->withToken($token)->getJson('/api/v1/queue-status')
            ->assertOk()
            ->assertJsonPath('data.stage', 'pending_registrar_approval')
            ->assertJsonPath('data.can_claim', false);
    }

    public function test_a_student_waiting_at_the_payment_hold_sees_that_stage_and_cannot_claim(): void
    {
        [$token] = $this->makeStudentWithEnrollment('2026-08-90010', EnrollmentStatus::AwaitingFirstPayment);

        $this->withToken($token)->getJson('/api/v1/queue-status')
            ->assertOk()
            ->assertJsonPath('data.stage', 'awaiting_first_payment')
            ->assertJsonPath('data.can_claim', false);
    }

    public function test_an_approved_student_with_no_ticket_yet_can_claim(): void
    {
        [$token] = $this->makeStudentWithEnrollment('2026-08-90003', EnrollmentStatus::PendingPayment);

        $this->withToken($token)->getJson('/api/v1/queue-status')
            ->assertOk()
            ->assertJsonPath('data.stage', 'pending_payment')
            ->assertJsonPath('data.can_claim', true)
            ->assertJsonPath('data.ticket', null);
    }

    public function test_an_enrolled_student_cannot_claim(): void
    {
        [$token] = $this->makeStudentWithEnrollment('2026-08-90004', EnrollmentStatus::Enrolled);

        $this->withToken($token)->getJson('/api/v1/queue-status')
            ->assertOk()
            ->assertJsonPath('data.stage', 'enrolled')
            ->assertJsonPath('data.can_claim', false);
    }

    public function test_a_student_with_a_ticket_sees_it_and_their_position(): void
    {
        [$token, $enrollment] = $this->makeStudentWithEnrollment('2026-08-90005', EnrollmentStatus::PendingPayment);
        $cycle = QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 0]);
        QueueTicket::create([
            'enrollment_id' => $enrollment->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => QueueServiceDate::today(), 'status' => QueueTicketStatus::Waiting,
        ]);

        $this->withToken($token)->getJson('/api/v1/queue-status')
            ->assertOk()
            ->assertJsonPath('data.can_claim', false)
            ->assertJsonPath('data.ticket.ticket_number', 'Q001')
            ->assertJsonPath('data.ticket.position', 0);
    }

    public function test_a_student_sees_how_many_times_their_ticket_has_been_announced(): void
    {
        [$token, $enrollment] = $this->makeStudentWithEnrollment('2026-08-90020', EnrollmentStatus::PendingPayment);
        $cycle = QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 0]);
        QueueTicket::create([
            'enrollment_id' => $enrollment->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => QueueServiceDate::today(),
            'status' => QueueTicketStatus::Serving, 'announce_count' => 3,
        ]);

        // The student's device compares this number between polls to know the
        // Cashier called the ticket out again.
        $this->withToken($token)->getJson('/api/v1/queue-status')
            ->assertOk()
            ->assertJsonPath('data.ticket.announce_count', 3);
    }

    public function test_the_board_shows_now_serving_and_the_first_ten_waiting_by_number_only(): void
    {
        [$token, $enrollment] = $this->makeStudentWithEnrollment('2026-08-90006', EnrollmentStatus::PendingPayment);
        $cycle = QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 0]);
        $servingTicket = QueueTicket::create([
            'enrollment_id' => $enrollment->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => QueueServiceDate::today(), 'status' => QueueTicketStatus::Serving,
        ]);
        $otherEnrollment = $this->makeAnotherPendingPaymentEnrollment('2026-08-90007');
        QueueTicket::create([
            'enrollment_id' => $otherEnrollment->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 2,
            'ticket_number' => 'Q002', 'queue_date' => QueueServiceDate::today(), 'status' => QueueTicketStatus::Waiting,
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/queue-status')->assertOk();

        $response->assertJsonPath('data.now_serving_ticket_number', 'Q001');
        $response->assertJsonPath('data.upcoming_ticket_numbers', ['Q002']);
        self::assertStringNotContainsString('2026-08-90007', $response->getContent());
    }

    public function test_priority_tickets_appear_first_on_the_board(): void
    {
        [$token, $enrollment] = $this->makeStudentWithEnrollment('2026-08-90008', EnrollmentStatus::PendingPayment);
        $cycle = QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 0]);
        QueueTicket::create([
            'enrollment_id' => $enrollment->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => QueueServiceDate::today(), 'status' => QueueTicketStatus::Waiting,
            'priority' => QueueTicketPriority::Regular,
        ]);
        $otherEnrollment = $this->makeAnotherPendingPaymentEnrollment('2026-08-90009');
        QueueTicket::create([
            'enrollment_id' => $otherEnrollment->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 2,
            'ticket_number' => 'Q002', 'queue_date' => QueueServiceDate::today(), 'status' => QueueTicketStatus::Waiting,
            'priority' => QueueTicketPriority::Priority,
        ]);

        $this->withToken($token)->getJson('/api/v1/queue-status')
            ->assertOk()
            ->assertJsonPath('data.upcoming_ticket_numbers', ['Q002', 'Q001']);
    }

    public function test_cut_off_today_is_true_while_the_open_cycle_is_cut_off(): void
    {
        [$token] = $this->makeStudentWithEnrollment('2026-08-90010', EnrollmentStatus::PendingPayment);
        QueueCycle::create([
            'opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 0,
            'cut_off_at' => now(), 'cut_off_service_date' => QueueServiceDate::today(),
        ]);

        $this->withToken($token)->getJson('/api/v1/queue-status')
            ->assertOk()
            ->assertJsonPath('data.cut_off_today', true);
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::query()->firstOrCreate(
            ['school_year' => '2026-2027', 'semester' => '1st'],
            ['status' => AcademicTermStatus::SemesterOngoing],
        );
    }

    /**
     * @return array{string, Enrollment}
     */
    private function makeStudentWithEnrollment(string $studentNumber, EnrollmentStatus $status): array
    {
        $enrollment = $this->makeAnotherPendingPaymentEnrollment($studentNumber, $status);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'student.'.$studentNumber.'@grc.test', 'password' => self::PASSWORD,
        ])->json('data.token');

        return [$token, $enrollment];
    }

    private function makeAnotherPendingPaymentEnrollment(string $studentNumber, EnrollmentStatus $status = EnrollmentStatus::PendingPayment): Enrollment
    {
        $term = $this->makeTerm();
        $program = Program::create(['code' => 'BSCS-SQV'.$studentNumber, 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Queue View Student', 'email' => 'student.'.$studentNumber.'@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $studentNumber,
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);

        return Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => $status, 'total_units' => 3,
        ]);
    }

    private function makeStudentToken(string $studentNumber): string
    {
        $user = User::create([
            'name' => 'No Enrollment Student', 'email' => 'noenroll.'.$studentNumber.'@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $studentNumber,
            'program_id' => Program::create(['code' => 'BSCS-NE'.$studentNumber, 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active])->id,
            'curriculum_id' => Curriculum::create([
                'program_id' => Program::query()->latest('id')->first()->id, 'name' => 'BSCS Curriculum',
                'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
            ])->id,
            'year_level' => 1, 'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }

    private function tokenFor(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value, 'email' => $email,
            'password' => self::PASSWORD, 'role' => $role, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => self::PASSWORD,
        ])->json('data.token');
    }
}

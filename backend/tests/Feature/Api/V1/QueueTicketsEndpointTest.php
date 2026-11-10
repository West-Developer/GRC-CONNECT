<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
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
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QueueTicketsEndpointTest extends TestCase
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

    private function makeStudent(Curriculum $curriculum, string $email = 'student.queue@grc.test', string $studentNumber = '2026-0001'): StudentProfile
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

    private function makeTicket(StudentProfile $student, AcademicTerm $term, string $ticketNumber, string $queueDate = '2026-08-01', QueueTicketStatus $status = QueueTicketStatus::Waiting): QueueTicket
    {
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment,
            'total_units' => 3,
            'submitted_at' => now(),
        ]);
        $cycle = QueueCycle::query()->whereNull('closed_at')->first()
            ?? QueueCycle::create(['opened_on' => $queueDate, 'last_ticket_sequence' => 0]);
        $sequence = $cycle->last_ticket_sequence + 1;
        $cycle->update(['last_ticket_sequence' => $sequence, 'last_claimed_on' => $queueDate]);

        return QueueTicket::create([
            'enrollment_id' => $enrollment->id,
            'queue_cycle_id' => $cycle->id,
            'ticket_sequence' => $sequence,
            'ticket_number' => $ticketNumber,
            'queue_date' => $queueDate,
            'status' => $status,
        ]);
    }

    public function test_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/queue-tickets')->assertUnauthorized();
    }

    public function test_a_non_accounting_role_cannot_view_any(): void
    {
        $token = $this->tokenForNewUser(UserRole::Student, 'student.forbidden@grc.test');

        $this->withToken($token)->getJson('/api/v1/queue-tickets')->assertForbidden();
    }

    public function test_index_lists_tickets_in_queue_date_then_id_order(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum);
        $studentB = $this->makeStudent($curriculum, 'second.student.queue@grc.test', '2026-0002');
        $later = $this->makeTicket($studentA, $term, 'Q000001', '2026-08-02');
        $earlier = $this->makeTicket($studentB, $term, 'Q000002', '2026-08-01');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.order@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/queue-tickets');

        $response->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $earlier->id);
        $response->assertJsonPath('data.1.id', $later->id);
    }

    public function test_waiting_pages_place_priority_tickets_before_regular_tickets(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $this->makeTicket($this->makeStudent($curriculum), $term, 'Q001');
        $priority = $this->makeTicket($this->makeStudent($curriculum, 'priority.page@grc.test', '2026-0002'), $term, 'Q002');
        $priority->update(['priority' => QueueTicketPriority::Priority]);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.priority.page@grc.test');

        $this->withToken($token)->getJson('/api/v1/queue-tickets?status=waiting&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $priority->id)->assertJsonPath('meta.total', 2);
    }

    public function test_served_pages_show_the_latest_served_ticket_first(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $older = $this->makeTicket($this->makeStudent($curriculum), $term, 'Q001', status: QueueTicketStatus::Served);
        $older->update(['served_at' => now()->subMinutes(5)]);
        $newer = $this->makeTicket($this->makeStudent($curriculum, 'served.page@grc.test', '2026-0002'), $term, 'Q002', status: QueueTicketStatus::Served);
        $newer->update(['served_at' => now()]);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.served.page@grc.test');

        $this->withToken($token)->getJson('/api/v1/queue-tickets?status=served&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $newer->id)->assertJsonPath('meta.total', 2);
    }

    public function test_index_can_filter_by_status_and_queue_date(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum);
        $studentB = $this->makeStudent($curriculum, 'second.student.filter@grc.test', '2026-0002');
        $this->makeTicket($studentA, $term, 'Q000001', '2026-08-01', QueueTicketStatus::Waiting);
        $served = $this->makeTicket($studentB, $term, 'Q000002', '2026-08-01', QueueTicketStatus::Served);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.filter@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/queue-tickets?status=served');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $served->id);
    }

    public function test_index_lists_a_never_requeued_ticket_before_a_requeued_ticket_on_a_timestamp_tie(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentB = $this->makeStudent($curriculum, 'b.tiebreak@grc.test', '2026-2001');
        $studentC = $this->makeStudent($curriculum, 'c.tiebreak@grc.test', '2026-2002');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.tiebreak@grc.test');

        try {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-01 09:00:00', 'UTC'));

            // B and C are created at the exact same frozen instant, so
            // both get the identical `created_at`. B has the lower id
            // (created first).
            $ticketB = $this->makeTicket($studentB, $term, 'Q000001');
            $ticketC = $this->makeTicket($studentC, $term, 'Q000002');

            // Skipping B while the clock is still frozen at the same
            // instant stamps its requeued_at to the exact same value as
            // C's created_at -- a true tie on
            // COALESCE(requeued_at, created_at) between a requeued ticket
            // (B) and a never-requeued one (C), despite B's lower id.
            $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticketB->id}", ['action' => 'skip'])->assertOk();

            $response = $this->withToken($token)->getJson('/api/v1/queue-tickets');

            // C (never requeued) sorts ahead of B (requeued) despite B's
            // lower id -- matching QueueTicket::position()'s own regime
            // split, which already gets this right.
            $response->assertOk()->assertJsonCount(2, 'data');
            $response->assertJsonPath('data.0.id', $ticketC->id);
            $response->assertJsonPath('data.1.id', $ticketB->id);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_serve_transitions_a_waiting_ticket_to_serving(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.serve@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'serve']);

        $response->assertOk()->assertJsonPath('data.status', 'serving');
        self::assertSame(AuditAction::QUEUE_TICKET_SERVING_STARTED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_serve_cannot_be_performed_from_serving(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001', '2026-08-01', QueueTicketStatus::Serving);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.doubleserve@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'serve']);

        $response->assertUnprocessable();
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_complete_transitions_a_serving_ticket_to_served(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001', '2026-08-01', QueueTicketStatus::Serving);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.complete@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'complete']);

        $response->assertOk()->assertJsonPath('data.status', 'served');
        self::assertNotNull($ticket->refresh()->served_at);
        self::assertSame(AuditAction::QUEUE_TICKET_SERVED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_announce_counts_each_call_out_of_a_serving_ticket_without_changing_it(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001', '2026-08-01', QueueTicketStatus::Serving);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.announce@grc.test');

        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'announce'])
            ->assertOk()
            ->assertJsonPath('data.status', 'serving');
        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'announce'])
            ->assertOk();

        self::assertSame(2, $ticket->refresh()->announce_count);
        self::assertSame(QueueTicketStatus::Serving, $ticket->status);
        // A repeatable operational ping, not a state change: nothing to audit.
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_announce_requires_the_ticket_to_be_serving(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.announcewaiting@grc.test');

        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'announce'])
            ->assertUnprocessable();

        self::assertSame(0, $ticket->refresh()->announce_count);
    }

    public function test_only_accounting_staff_can_announce_a_ticket(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001', '2026-08-01', QueueTicketStatus::Serving);
        $token = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar.announce@grc.test');

        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'announce'])
            ->assertForbidden();

        self::assertSame(0, $ticket->refresh()->announce_count);
    }

    public function test_a_non_accounting_role_cannot_transition_a_ticket(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001');
        $token = $this->tokenForNewUser(UserRole::RegistrarHead, 'registrar.forbiddenqueue@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'serve']);

        $response->assertForbidden();
        self::assertSame('waiting', $ticket->refresh()->status->value);
    }

    public function test_skip_requeues_a_waiting_ticket_to_the_back_of_line(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.skipwaiting@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'skip']);

        $response->assertOk()->assertJsonPath('data.status', 'waiting');
        $response->assertJsonPath('data.requeued_at', fn ($value) => $value !== null);
        $ticket->refresh();
        self::assertSame('waiting', $ticket->status->value);
        self::assertNotNull($ticket->requeued_at);
        self::assertSame(AuditAction::QUEUE_TICKET_SKIPPED, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_skip_requeues_a_serving_ticket_to_the_back_of_line(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001', '2026-08-01', QueueTicketStatus::Serving);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.skipserving@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'skip']);

        $response->assertOk()->assertJsonPath('data.status', 'waiting');
        self::assertNotNull($ticket->refresh()->requeued_at);
    }

    public function test_a_requeued_ticket_moves_behind_tickets_with_a_higher_id(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'a.queue@grc.test', '2026-1001');
        $studentB = $this->makeStudent($curriculum, 'b.queue@grc.test', '2026-1002');
        $studentC = $this->makeStudent($curriculum, 'c.queue@grc.test', '2026-1003');
        $ticketA = $this->makeTicket($studentA, $term, 'Q000001');
        $ticketB = $this->makeTicket($studentB, $term, 'Q000002', '2026-08-01', QueueTicketStatus::Serving);
        $ticketC = $this->makeTicket($studentC, $term, 'Q000003');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.requeueorder@grc.test');

        self::assertSame(0, $ticketA->position());
        self::assertSame(1, $ticketC->position());

        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticketB->id}", ['action' => 'skip'])->assertOk();

        // B re-enters waiting behind C, even though B's id predates C's --
        // proving ordering now follows the requeue moment, not id.
        self::assertSame(0, $ticketA->refresh()->position());
        self::assertSame(1, $ticketC->refresh()->position());
        self::assertSame(2, $ticketB->refresh()->position());
    }

    public function test_a_requeued_priority_ticket_still_precedes_regular_tickets(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'a.queue@grc.test', '2026-1001');
        $studentB = $this->makeStudent($curriculum, 'b.queue@grc.test', '2026-1002');
        $ticketA = $this->makeTicket($studentA, $term, 'Q000001');
        $ticketB = $this->makeTicket($studentB, $term, 'Q000002', '2026-08-01', QueueTicketStatus::Serving);
        $ticketB->update(['priority' => QueueTicketPriority::Priority]);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.requeuepriority@grc.test');

        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticketB->id}", ['action' => 'skip'])->assertOk();

        // B is Priority; even requeued to the back of ITS tier, it still
        // precedes every Regular ticket, including A which never left waiting.
        self::assertSame(0, $ticketB->refresh()->position());
        self::assertSame(1, $ticketA->refresh()->position());
    }

    public function test_a_carry_over_ticket_from_an_earlier_date_stays_ahead_of_todays_new_tickets(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'a.carry@grc.test', '2026-1001');
        $studentB = $this->makeStudent($curriculum, 'b.carry@grc.test', '2026-1002');
        $carryOver = $this->makeTicket($studentA, $term, 'Q000048', '2026-08-22');
        $today = $this->makeTicket($studentB, $term, 'Q000050', '2026-08-23');

        self::assertSame($carryOver->queue_cycle_id, $today->queue_cycle_id);
        self::assertSame(0, $carryOver->position());
        self::assertSame(1, $today->position());
    }

    public function test_a_carry_over_ticket_re_skipped_today_stays_ahead_of_todays_new_tickets_only(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'a.reskip@grc.test', '2026-1001');
        $studentB = $this->makeStudent($curriculum, 'b.reskip@grc.test', '2026-1002');
        $carryOver = $this->makeTicket($studentA, $term, 'Q000048', '2026-08-22', QueueTicketStatus::Serving);
        $today = $this->makeTicket($studentB, $term, 'Q000050', '2026-08-23');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.reskip@grc.test');

        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$carryOver->id}", ['action' => 'skip'])->assertOk();

        // Skipped again today, but it still belongs to Friday's group --
        // it goes to the back of ITS OWN queue_date, not behind Saturday's
        // walk-ins.
        self::assertSame(0, $carryOver->refresh()->position());
        self::assertSame(1, $today->refresh()->position());
    }

    public function test_skip_cannot_be_performed_from_served(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001', '2026-08-01', QueueTicketStatus::Served);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.skipserved@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'skip']);

        $response->assertUnprocessable();
    }

    public function test_serving_a_new_ticket_completes_whatever_was_already_being_served_that_day(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'first.serving@grc.test', '2026-0001');
        $studentB = $this->makeStudent($curriculum, 'second.serving@grc.test', '2026-0002');
        $alreadyServing = $this->makeTicket($studentA, $term, 'Q000001', '2026-08-01', QueueTicketStatus::Serving);
        $nextUp = $this->makeTicket($studentB, $term, 'Q000002', '2026-08-01', QueueTicketStatus::Waiting);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.singleactive@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$nextUp->id}", ['action' => 'serve']);

        $response->assertOk()->assertJsonPath('data.status', 'serving');
        self::assertSame('served', $alreadyServing->refresh()->status->value);
        self::assertNotNull($alreadyServing->refresh()->served_at);
    }

    public function test_serving_a_new_ticket_completes_a_carry_over_still_serving_from_an_earlier_date(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'a.carryserve@grc.test', '2026-1001');
        $studentB = $this->makeStudent($curriculum, 'b.carryserve@grc.test', '2026-1002');
        $carryOver = $this->makeTicket($studentA, $term, 'Q000048', '2026-08-22', QueueTicketStatus::Serving);
        $today = $this->makeTicket($studentB, $term, 'Q000050', '2026-08-23');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.carryserve@grc.test');

        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$today->id}", ['action' => 'serve'])->assertOk();

        // Before this fix, the bulk-complete was scoped to queue_date, so a
        // carry-over `serving` ticket from an earlier date was left
        // serving forever -- two simultaneous "now serving" tickets.
        self::assertSame('served', $carryOver->refresh()->status->value);
        self::assertSame('serving', $today->refresh()->status->value);
    }

    public function test_serve_is_blocked_while_the_cycle_is_cut_off_for_today(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum, 'a.cutoffserve@grc.test', '2026-1001');
        $ticket = $this->makeTicket($student, $term, 'Q000001');
        $ticket->cycle->update(['cut_off_at' => now(), 'cut_off_service_date' => QueueServiceDate::today()]);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.cutoffserve@grc.test');

        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'serve'])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.action.0', 'The queue is cut off for today. Resume it before serving another ticket.');
    }

    public function test_serving_a_ticket_records_who_served_it(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001');
        $accounting = User::create([
            'name' => 'Cashier One', 'email' => 'accounting.servedby@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::AccountingStaff, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'accounting.servedby@grc.test', 'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'serve'])->assertOk();

        self::assertSame($accounting->id, $ticket->refresh()->served_by);
    }

    public function test_mark_priority_flags_a_waiting_ticket(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001');
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.markpriority@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'mark_priority']);

        $response->assertOk()->assertJsonPath('data.priority', 'priority');
        // Not a status transition — the ticket stays waiting.
        $response->assertJsonPath('data.status', 'waiting');
        self::assertSame(AuditAction::QUEUE_TICKET_MARKED_PRIORITY, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_mark_priority_cannot_be_performed_once_serving(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $student = $this->makeStudent($curriculum);
        $ticket = $this->makeTicket($student, $term, 'Q000001', '2026-08-01', QueueTicketStatus::Serving);
        $token = $this->tokenForNewUser(UserRole::AccountingStaff, 'accounting.priorityserving@grc.test');

        $response = $this->withToken($token)->patchJson("/api/v1/queue-tickets/{$ticket->id}", ['action' => 'mark_priority']);

        $response->assertUnprocessable();
    }

    public function test_position_counts_priority_tickets_ahead_of_regular_ones_regardless_of_issuance_order(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $studentA = $this->makeStudent($curriculum, 'position.a@grc.test', '2026-0001');
        $studentB = $this->makeStudent($curriculum, 'position.b@grc.test', '2026-0002');
        $studentC = $this->makeStudent($curriculum, 'position.c@grc.test', '2026-0003');

        // Issued in this order: regular, regular, priority. A later-issued
        // priority ticket still jumps ahead of every regular one.
        $firstRegular = $this->makeTicket($studentA, $term, 'Q001', '2026-08-01');
        $secondRegular = $this->makeTicket($studentB, $term, 'Q002', '2026-08-01');
        $laterPriority = $this->makeTicket($studentC, $term, 'Q003', '2026-08-01');
        $laterPriority->update(['priority' => 'priority']);

        self::assertSame(0, $laterPriority->position(), 'The only priority ticket has nothing ahead of it.');
        self::assertSame(1, $firstRegular->position(), 'One priority ticket stands ahead of the first regular one.');
        self::assertSame(2, $secondRegular->position(), 'The priority ticket plus the earlier regular one stand ahead.');
    }
}

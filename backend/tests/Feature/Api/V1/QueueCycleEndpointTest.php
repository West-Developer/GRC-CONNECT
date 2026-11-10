<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditAction;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Enrollment\QueueServiceDate;
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
use App\Models\Notification;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QueueCycleEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_show_returns_null_when_no_cycle_is_open(): void
    {
        $token = $this->tokenFor(UserRole::AccountingStaff, 'show.none@grc.test');

        $this->withToken($token)->getJson('/api/v1/queue-cycle')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_show_returns_the_open_cycle(): void
    {
        QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 3]);
        $token = $this->tokenFor(UserRole::AccountingStaff, 'show.open@grc.test');

        $this->withToken($token)->getJson('/api/v1/queue-cycle')
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_accounting_staff_can_cut_off_the_open_queue(): void
    {
        QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 3]);
        $token = $this->tokenFor(UserRole::AccountingStaff, 'cutoff.ok@grc.test');

        $this->withToken($token)->postJson('/api/v1/queue-cycle/cut-off')
            ->assertOk()
            ->assertJsonPath('data.status', 'cut_off');
        self::assertSame(AuditAction::QUEUE_CYCLE_CUT_OFF, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole()->action);
    }

    public function test_cutting_off_returns_a_serving_ticket_to_waiting_without_requeuing(): void
    {
        [, , $servingTicket] = $this->makeTicket(status: QueueTicketStatus::Serving);
        $token = $this->tokenFor(UserRole::AccountingStaff, 'cutoff.serving@grc.test');

        $this->withToken($token)->postJson('/api/v1/queue-cycle/cut-off')->assertOk();

        $servingTicket->refresh();
        self::assertSame('waiting', $servingTicket->status->value);
        self::assertNull($servingTicket->requeued_at);
    }

    public function test_cutting_off_notifies_every_waiting_student(): void
    {
        [$student] = $this->makeTicket(status: QueueTicketStatus::Waiting);
        $token = $this->tokenFor(UserRole::AccountingStaff, 'cutoff.notify@grc.test');

        $this->withToken($token)->postJson('/api/v1/queue-cycle/cut-off')->assertOk();

        self::assertSame(1, Notification::query()->where('user_id', $student->user_id)->count());
    }

    public function test_cannot_cut_off_twice_in_one_day(): void
    {
        $this->makeTicket();
        $token = $this->tokenFor(UserRole::AccountingStaff, 'cutoff.twice@grc.test');
        $this->withToken($token)->postJson('/api/v1/queue-cycle/cut-off')->assertOk();

        $this->withToken($token)->postJson('/api/v1/queue-cycle/cut-off')
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.cycle.0', 'The queue is already cut off for today.');
    }

    public function test_cannot_cut_off_when_no_cycle_is_open(): void
    {
        $token = $this->tokenFor(UserRole::AccountingStaff, 'cutoff.none@grc.test');

        $this->withToken($token)->postJson('/api/v1/queue-cycle/cut-off')
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.cycle.0', 'No queue is currently open.');
    }

    public function test_accounting_staff_can_resume_a_cut_off_cycle(): void
    {
        $this->makeTicket();
        $token = $this->tokenFor(UserRole::AccountingStaff, 'resume.ok@grc.test');
        $this->withToken($token)->postJson('/api/v1/queue-cycle/cut-off')->assertOk();

        $this->withToken($token)->postJson('/api/v1/queue-cycle/resume')
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
        self::assertSame(AuditAction::QUEUE_CYCLE_RESUMED, AuditLog::query()->latest('id')->first()->action);
    }

    public function test_cannot_resume_when_not_cut_off(): void
    {
        QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 1]);
        $token = $this->tokenFor(UserRole::AccountingStaff, 'resume.notcutoff@grc.test');

        $this->withToken($token)->postJson('/api/v1/queue-cycle/resume')
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.cycle.0', 'The queue is not currently cut off.');
    }

    public function test_a_non_accounting_role_cannot_cut_off(): void
    {
        $this->makeTicket();
        $token = $this->tokenFor(UserRole::RegistrarStaff, 'cutoff.forbidden@grc.test');

        $this->withToken($token)->postJson('/api/v1/queue-cycle/cut-off')->assertForbidden();
    }

    /**
     * @return array{StudentProfile, Enrollment, QueueTicket}
     */
    private function makeTicket(QueueTicketStatus $status = QueueTicketStatus::Waiting): array
    {
        $term = AcademicTerm::query()->firstOrCreate(
            ['school_year' => '2026-2027', 'semester' => '1st'],
            ['status' => AcademicTermStatus::SemesterOngoing],
        );
        $program = Program::create(['code' => 'BSCS-QCT'.uniqid(), 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Cycle Ticket Student', 'email' => 'cycleticket.'.uniqid().'@grc.test',
            'password' => self::PASSWORD, 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-08-'.random_int(10000, 99999),
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment, 'total_units' => 3,
        ]);
        $cycle = QueueCycle::query()->whereNull('closed_at')->first()
            ?? QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 0]);
        $sequence = $cycle->last_ticket_sequence + 1;
        $cycle->update(['last_ticket_sequence' => $sequence, 'last_claimed_on' => QueueServiceDate::today()]);
        $ticket = QueueTicket::create([
            'enrollment_id' => $enrollment->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => $sequence,
            'ticket_number' => sprintf('Q%03d', $sequence), 'queue_date' => QueueServiceDate::today(), 'status' => $status,
        ]);

        return [$student, $enrollment, $ticket];
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

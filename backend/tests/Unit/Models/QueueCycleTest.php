<?php

namespace Tests\Unit\Models;

use App\Domain\Enrollment\QueueCycleStatus;
use App\Domain\Enrollment\QueueServiceDate;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Models\QueueCycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QueueCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_freshly_opened_cycle_with_no_tickets_is_drained(): void
    {
        $cycle = QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 0]);

        self::assertTrue($cycle->isDrained());
    }

    public function test_status_is_open_by_default(): void
    {
        $cycle = QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 0]);

        self::assertSame(QueueCycleStatus::Open, $cycle->status());
    }

    public function test_status_is_cut_off_only_for_todays_cut_off_service_date(): void
    {
        CarbonImmutable::setTestNow('2026-08-23 09:00:00');
        $cycle = QueueCycle::create([
            'opened_on' => '2026-08-23', 'last_ticket_sequence' => 3,
            'cut_off_at' => now(), 'cut_off_service_date' => '2026-08-23',
        ]);

        self::assertSame(QueueCycleStatus::CutOff, $cycle->status());

        CarbonImmutable::setTestNow('2026-08-24 09:00:00');
        self::assertSame(QueueCycleStatus::Open, $cycle->fresh()->status());
    }

    public function test_status_is_closed_once_closed_at_is_set(): void
    {
        $cycle = QueueCycle::create([
            'opened_on' => '2026-08-20', 'last_ticket_sequence' => 3, 'closed_at' => now(),
        ]);

        self::assertSame(QueueCycleStatus::Closed, $cycle->status());
    }

    public function test_it_is_not_drained_while_a_pending_payment_ticket_is_waiting(): void
    {
        $cycle = QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 1]);
        [, $enrollment] = $this->makeApprovedEnrollment();
        $cycle->tickets()->create([
            'enrollment_id' => $enrollment->id, 'ticket_number' => 'Q001', 'ticket_sequence' => 1,
            'queue_date' => QueueServiceDate::today(), 'status' => QueueTicketStatus::Waiting,
        ]);

        self::assertFalse($cycle->fresh()->isDrained());
    }

    public function test_it_is_drained_once_the_waiting_tickets_enrollment_is_paid(): void
    {
        $cycle = QueueCycle::create(['opened_on' => QueueServiceDate::today(), 'last_ticket_sequence' => 1]);
        [, $enrollment] = $this->makeApprovedEnrollment();
        $ticket = $cycle->tickets()->create([
            'enrollment_id' => $enrollment->id, 'ticket_number' => 'Q001', 'ticket_sequence' => 1,
            'queue_date' => QueueServiceDate::today(), 'status' => QueueTicketStatus::Waiting,
        ]);
        // Left `waiting` even though payment was confirmed -- ConfirmPayment
        // does not touch the queue ticket today (a known, documented gap).
        // isDrained() must not let this block the cycle from ever resetting.
        $enrollment->update(['status' => 'enrolled']);

        self::assertTrue($cycle->fresh()->isDrained());
        self::assertSame('waiting', $ticket->fresh()->status->value);
    }

    /**
     * @return array{\App\Models\StudentProfile, \App\Models\Enrollment}
     */
    private function makeApprovedEnrollment(): array
    {
        $program = \App\Models\Program::create([
            'code' => 'BSCS-QC', 'name' => 'BS Computer Science', 'status' => \App\Domain\Organization\ProgramStatus::Active,
        ]);
        $curriculum = \App\Models\Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSCS Curriculum',
            'effective_school_year' => '2026-2027', 'status' => \App\Domain\Curriculum\CurriculumStatus::Active,
        ]);
        $term = \App\Models\AcademicTerm::create([
            'school_year' => '2026-2027', 'semester' => '1st', 'status' => \App\Domain\Organization\AcademicTermStatus::SemesterOngoing,
        ]);
        $user = \App\Models\User::create([
            'name' => 'Cycle Test Student', 'email' => 'cycle.test.'.uniqid().'@grc.test',
            'password' => 'correct-horse-battery-staple', 'role' => \App\Domain\Identity\UserRole::Student,
            'status' => \App\Domain\Identity\UserStatus::Active,
        ]);
        $student = \App\Models\StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-08-'.random_int(10000, 99999),
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => \App\Domain\Identity\AdmissionStatus::Admitted,
            'academic_standing' => \App\Domain\Identity\AcademicStanding::Good,
        ]);
        $enrollment = \App\Models\Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => 'pending_payment', 'total_units' => 3,
        ]);

        return [$student, $enrollment];
    }
}

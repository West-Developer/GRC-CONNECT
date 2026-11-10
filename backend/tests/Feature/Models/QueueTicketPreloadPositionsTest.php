<?php

namespace Tests\Feature\Models;

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
use App\Models\Curriculum;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `QueueTicket::position()` runs one or two COUNT queries per waiting ticket, so
 * a list page of enrollments paid one to three extra queries per row on every
 * poll. `preloadPositions()` computes a whole page in memory from one query and
 * MUST give exactly the numbers `position()` gives (ADR 0029).
 */
final class QueueTicketPreloadPositionsTest extends TestCase
{
    use RefreshDatabase;

    private int $studentSeq = 0;

    private function ticket(AcademicTerm $term, QueueCycle $cycle, string $queueDate, array $overrides = []): QueueTicket
    {
        $this->studentSeq++;
        $program = Program::query()->firstOrCreate(['code' => 'BSCS'], ['name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::query()->firstOrCreate(
            ['program_id' => $program->id, 'name' => 'BSCS Curriculum'],
            ['effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active],
        );
        $user = User::create([
            'name' => 'Queue Student '.$this->studentSeq, 'email' => "queue{$this->studentSeq}@grc.test",
            'password' => 'correct-horse-battery-staple', 'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);
        $student = StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-'.str_pad((string) $this->studentSeq, 4, '0', STR_PAD_LEFT),
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id, 'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment, 'total_units' => 3, 'submitted_at' => now(),
        ]);
        $sequence = $cycle->last_ticket_sequence + 1;
        $cycle->update(['last_ticket_sequence' => $sequence]);

        return QueueTicket::create(array_merge([
            'enrollment_id' => $enrollment->id, 'queue_cycle_id' => $cycle->id,
            'ticket_sequence' => $sequence, 'ticket_number' => sprintf('Q%06d', $sequence + $cycle->id * 100),
            'queue_date' => $queueDate, 'status' => QueueTicketStatus::Waiting,
        ], $overrides));
    }

    public function test_preloaded_positions_match_position_exactly_and_cost_no_more_queries(): void
    {
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => '1st', 'status' => AcademicTermStatus::SemesterOngoing]);
        $open = QueueCycle::create(['opened_on' => '2026-08-22', 'last_ticket_sequence' => 0]);
        $closed = QueueCycle::create(['opened_on' => '2026-08-01', 'closed_at' => now(), 'last_ticket_sequence' => 0]);

        // Open cycle: a carry-over from an earlier day, same-day walk-ins with
        // identical timestamps (ties), priority and requeued tickets, one being served.
        $ids = [];
        foreach ([
            ['2026-08-22', []],
            ['2026-08-22', []],
            ['2026-08-23', []],
            ['2026-08-23', []],
            ['2026-08-23', ['priority' => QueueTicketPriority::Priority]],
            ['2026-08-23', []],
            ['2026-08-23', ['priority' => QueueTicketPriority::Priority]],
            ['2026-08-23', ['status' => QueueTicketStatus::Serving]],
            ['2026-08-22', ['priority' => QueueTicketPriority::Priority]],
        ] as [$date, $overrides]) {
            $ids[] = $this->ticket($term, $open, $date, $overrides)->id;
        }
        foreach ([['2026-08-01', []], ['2026-08-01', []]] as [$date, $overrides]) {
            $ids[] = $this->ticket($term, $closed, $date, $overrides)->id;
        }

        // Deterministic timestamps: a tie among the 2026-08-23 walk-ins, and two
        // tickets re-queued (one to the same second as a tie, one later).
        $tie = '2026-08-23 09:00:00';
        foreach ([$ids[2], $ids[3], $ids[4], $ids[5], $ids[6]] as $id) {
            DB::table('queue_tickets')->where('id', $id)->update(['created_at' => $tie]);
        }
        DB::table('queue_tickets')->where('id', $ids[2])->update(['requeued_at' => $tie]);
        DB::table('queue_tickets')->where('id', $ids[5])->update(['requeued_at' => '2026-08-23 09:30:00']);
        DB::table('queue_tickets')->where('id', $ids[0])->update(['created_at' => '2026-08-22 08:00:00']);
        DB::table('queue_tickets')->where('id', $ids[1])->update(['created_at' => '2026-08-22 08:05:00']);
        DB::table('queue_tickets')->where('id', $ids[8])->update(['created_at' => '2026-08-22 08:10:00']);

        $expected = QueueTicket::query()->get()->mapWithKeys(fn (QueueTicket $t): array => [$t->id => $t->position()])->all();
        self::assertContains(null, $expected, 'the serving ticket has no position');
        self::assertGreaterThan(3, count(array_filter($expected, fn ($p) => $p !== null)));

        $tickets = QueueTicket::query()->get();
        QueueTicket::preloadPositions($tickets);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $actual = $tickets->mapWithKeys(fn (QueueTicket $t): array => [$t->id => $t->position()])->all();

        self::assertSame($expected, $actual);
        self::assertSame(0, $queries, 'position() after preloadPositions() must not query');
    }

    public function test_preloading_an_empty_or_all_served_page_is_harmless(): void
    {
        QueueTicket::preloadPositions([]);

        $this->addToAssertionCount(1);
    }
}

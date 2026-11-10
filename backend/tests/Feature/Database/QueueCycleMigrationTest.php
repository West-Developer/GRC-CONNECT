<?php

namespace Tests\Feature\Database;

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
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\QueueTicket;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class QueueCycleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_cycles_table_has_the_expected_columns(): void
    {
        self::assertTrue(Schema::hasColumns('queue_cycles', [
            'id', 'opened_on', 'last_claimed_on', 'last_ticket_sequence',
            'cut_off_at', 'cut_off_service_date', 'cut_off_by', 'closed_at',
            'open_marker', 'created_at', 'updated_at',
        ]));
    }

    public function test_queue_tickets_gained_the_cycle_columns(): void
    {
        self::assertTrue(Schema::hasColumns('queue_tickets', ['queue_cycle_id', 'ticket_sequence']));
    }

    public function test_only_one_open_cycle_is_allowed_at_a_time(): void
    {
        QueueCycle::create(['opened_on' => '2026-08-23', 'last_ticket_sequence' => 0]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        QueueCycle::create(['opened_on' => '2026-08-23', 'last_ticket_sequence' => 0]);
    }

    public function test_a_closed_cycle_does_not_collide_with_a_new_open_one(): void
    {
        QueueCycle::create([
            'opened_on' => '2026-08-20', 'last_ticket_sequence' => 5, 'closed_at' => now(),
        ]);

        $secondCycle = QueueCycle::create(['opened_on' => '2026-08-23', 'last_ticket_sequence' => 0]);

        self::assertNull($secondCycle->closed_at);
        self::assertSame(2, QueueCycle::query()->count());
    }

    public function test_queue_cycle_id_and_ticket_sequence_are_required(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $enrollment = $this->makeEnrollment($curriculum, $term, 'student.required@grc.test', '2026-9001');

        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::table('queue_tickets')->insert([
            'enrollment_id' => $enrollment->id, 'ticket_number' => 'QZZZ', 'queue_date' => '2026-08-23',
            'status' => 'waiting', 'priority' => 'regular', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_ticket_sequence_is_unique_within_a_cycle(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();
        $cycle = QueueCycle::create(['opened_on' => '2026-08-23', 'last_ticket_sequence' => 1]);

        $firstEnrollment = $this->makeEnrollment($curriculum, $term, 'student.seq1@grc.test', '2026-9002');
        \Illuminate\Support\Facades\DB::table('queue_tickets')->insert([
            'enrollment_id' => $firstEnrollment->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q001', 'queue_date' => '2026-08-23', 'status' => 'waiting',
            'priority' => 'regular', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $secondEnrollment = $this->makeEnrollment($curriculum, $term, 'student.seq2@grc.test', '2026-9003');
        \Illuminate\Support\Facades\DB::table('queue_tickets')->insert([
            'enrollment_id' => $secondEnrollment->id, 'queue_cycle_id' => $cycle->id, 'ticket_sequence' => 1,
            'ticket_number' => 'Q002', 'queue_date' => '2026-08-23', 'status' => 'waiting',
            'priority' => 'regular', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Proves the I1 fix: the pre-fix `backfillCycles()` closed every
     * backfilled cycle except the single MOST RECENT date that still had an
     * outstanding (`waiting`/`serving`) ticket -- so an earlier outstanding
     * date got closed anyway, stranding its live ticket in a closed cycle
     * (a state `QueueCycle::isDrained()`, the cycle-scoped single-active-
     * serving guard, and `cycle=open` list filters all assume cannot
     * happen). The corrected algorithm merges every outstanding date into
     * ONE open cycle, however many distinct dates that spans, and only ever
     * closes a date that has no outstanding ticket of its own.
     *
     * `RefreshDatabase` always migrates from an empty database, so `up()`'s
     * call to `backfillCycles()` sees zero existing tickets and returns
     * immediately -- the method body would otherwise be entirely untested.
     * This test exercises the real logic directly: `backfillCycles()`/
     * `backfillOneCycle()` are `public` for exactly this reason (requiring
     * the migration file returns a fresh instance -- the same
     * anonymous-class pattern Laravel's own Migrator uses to resolve a
     * migration), rather than relying on a fragile full migration replay.
     *
     * A throwaway CLOSED placeholder cycle stands in for "not yet
     * backfilled" `queue_cycle_id`/`ticket_sequence` values: both columns
     * are NOT NULL by the time `RefreshDatabase` finishes migrating (the
     * very next migration tightens them), so a raw insert needs SOME valid
     * value satisfying NOT NULL/FK/uniqueness. `backfillOneCycle()`
     * unconditionally overwrites both columns on every ticket it touches,
     * so the placeholder's actual values never matter. It must be CLOSED so
     * it never collides with the real open cycle the algorithm creates for
     * the outstanding dates (`queue_cycles_single_open_cycle_unique`
     * allows only one open cycle at a time).
     */
    public function test_backfill_merges_every_outstanding_date_into_one_open_cycle(): void
    {
        $term = $this->makeTerm();
        $curriculum = $this->makeCurriculum();

        $placeholder = QueueCycle::create([
            'opened_on' => '2020-01-01', 'last_ticket_sequence' => 3, 'closed_at' => now(),
        ]);

        // Date A and date C both have an outstanding ticket; date B, in
        // between, is fully completed (served). The pre-fix algorithm left
        // only C -- the single most recent outstanding date -- open, and
        // closed every other date, including A.
        $ticketAId = $this->seedRawTicket($placeholder->id, 1, $curriculum, $term, 'A', 'waiting', '2026-08-01');
        $ticketBId = $this->seedRawTicket($placeholder->id, 2, $curriculum, $term, 'B', 'served', '2026-08-02');
        $ticketCId = $this->seedRawTicket($placeholder->id, 3, $curriculum, $term, 'C', 'serving', '2026-08-03');

        $migration = require base_path('database/migrations/2026_08_23_000001_create_queue_cycles_and_backfill_ticket_cycles.php');
        $migration->backfillCycles();

        $ticketA = QueueTicket::findOrFail($ticketAId);
        $ticketB = QueueTicket::findOrFail($ticketBId);
        $ticketC = QueueTicket::findOrFail($ticketCId);

        self::assertSame(
            $ticketA->queue_cycle_id,
            $ticketC->queue_cycle_id,
            'Both outstanding dates (A and C) must land in the SAME cycle.',
        );
        self::assertNotSame(
            $ticketA->queue_cycle_id,
            $ticketB->queue_cycle_id,
            "B has no outstanding ticket, so it must NOT share A and C's cycle.",
        );

        $mergedCycle = QueueCycle::findOrFail($ticketA->queue_cycle_id);
        $historicalCycle = QueueCycle::findOrFail($ticketB->queue_cycle_id);

        self::assertNull(
            $mergedCycle->closed_at,
            "The cycle holding A's still-waiting ticket must NOT be closed -- "
            .'this is exactly the bug: the old algorithm closed every date '
            .'except the single most recent outstanding one (C), stranding '
            ."A's ticket in a closed cycle.",
        );
        self::assertNotNull($historicalCycle->closed_at, 'B has no outstanding ticket, so its cycle is safely closed.');
        self::assertSame(
            1,
            QueueCycle::query()->whereNull('closed_at')->count(),
            'Exactly one open cycle exists system-wide after backfill.',
        );

        // ticket_sequence stays unique within the merged cycle even though A
        // and C came from two different original queue_dates.
        self::assertNotSame($ticketA->ticket_sequence, $ticketC->ticket_sequence);
    }

    private function seedRawTicket(
        int $placeholderCycleId,
        int $placeholderSequence,
        Curriculum $curriculum,
        AcademicTerm $term,
        string $suffix,
        string $status,
        string $queueDate,
    ): int {
        $enrollment = $this->makeEnrollment($curriculum, $term, "student.backfill{$suffix}@grc.test", "2026-91{$placeholderSequence}");

        return \Illuminate\Support\Facades\DB::table('queue_tickets')->insertGetId([
            'enrollment_id' => $enrollment->id,
            'queue_cycle_id' => $placeholderCycleId,
            'ticket_sequence' => $placeholderSequence,
            'ticket_number' => 'Q00'.$placeholderSequence,
            'queue_date' => $queueDate,
            'status' => $status,
            'priority' => 'regular',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

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

    private function makeEnrollment(Curriculum $curriculum, AcademicTerm $term, string $email, string $studentNumber): Enrollment
    {
        $user = User::create([
            'name' => 'Test Student', 'email' => $email,
            'password' => 'correct-horse-battery-staple', 'role' => UserRole::Student, 'status' => UserStatus::Active,
        ]);

        $student = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $studentNumber,
            'program_id' => $curriculum->program_id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);

        return Enrollment::create([
            'student_id' => $student->id,
            'academic_term_id' => $term->id,
            'status' => EnrollmentStatus::PendingPayment,
            'total_units' => 3,
            'submitted_at' => now(),
        ]);
    }
}

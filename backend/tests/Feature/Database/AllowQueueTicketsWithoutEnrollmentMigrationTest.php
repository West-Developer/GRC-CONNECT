<?php

namespace Tests\Feature\Database;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Enrollment\QueueTicketPriority;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\QueueCycle;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class AllowQueueTicketsWithoutEnrollmentMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    private const MIGRATION = '2026_10_04_000002_allow_queue_tickets_without_an_enrollment';

    public function test_a_queue_ticket_can_belong_to_a_student_profile_instead_of_an_enrollment(): void
    {
        $this->assertTrue(Schema::hasColumns('queue_tickets', [
            'student_profile_id', 'public_token_hash', 'link_sent_at', 'link_failed_at',
        ]));

        $profile = $this->studentProfile();
        $cycle = QueueCycle::create(['opened_on' => now()->toDateString(), 'last_ticket_sequence' => 0]);

        DB::table('queue_tickets')->insert([
            'enrollment_id' => null,
            'student_profile_id' => $profile->id,
            'queue_cycle_id' => $cycle->id,
            'ticket_sequence' => 1,
            'ticket_number' => 'Q001',
            'queue_date' => now()->toDateString(),
            'status' => QueueTicketStatus::Waiting->value,
            'priority' => QueueTicketPriority::Regular->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, DB::table('queue_tickets')->whereNull('enrollment_id')->count());
    }

    public function test_a_queue_ticket_with_neither_an_enrollment_nor_a_student_profile_is_refused(): void
    {
        $cycle = QueueCycle::create(['opened_on' => now()->toDateString(), 'last_ticket_sequence' => 0]);

        $this->expectException(QueryException::class);
        // The CHECK is what refuses it, not a missing-column or NOT NULL error.
        $this->expectExceptionMessageMatches('/queue_tickets_has_an_owner/');

        DB::table('queue_tickets')->insert([
            'enrollment_id' => null,
            'student_profile_id' => null,
            'queue_cycle_id' => $cycle->id,
            'ticket_sequence' => 1,
            'ticket_number' => 'Q001',
            'queue_date' => now()->toDateString(),
            'status' => QueueTicketStatus::Waiting->value,
            'priority' => QueueTicketPriority::Regular->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_student_profile_can_hold_only_one_queue_ticket(): void
    {
        $profile = $this->studentProfile();
        $cycle = QueueCycle::create(['opened_on' => now()->toDateString(), 'last_ticket_sequence' => 0]);
        $row = fn (int $sequence): array => [
            'enrollment_id' => null,
            'student_profile_id' => $profile->id,
            'queue_cycle_id' => $cycle->id,
            'ticket_sequence' => $sequence,
            'ticket_number' => sprintf('Q%03d', $sequence),
            'queue_date' => now()->toDateString(),
            'status' => QueueTicketStatus::Waiting->value,
            'priority' => QueueTicketPriority::Regular->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('queue_tickets')->insert($row(1));

        $this->expectException(QueryException::class);

        DB::table('queue_tickets')->insert($row(2));
    }

    public function test_student_profiles_gain_a_freshman_payment_confirmed_at_column(): void
    {
        $this->assertTrue(Schema::hasColumn('student_profiles', 'freshman_payment_confirmed_at'));
    }

    public function test_it_rolls_back_cleanly_and_discards_tickets_that_have_no_enrollment(): void
    {
        $profile = $this->studentProfile();
        $cycle = QueueCycle::create(['opened_on' => now()->toDateString(), 'last_ticket_sequence' => 0]);
        DB::table('queue_tickets')->insert([
            'enrollment_id' => null,
            'student_profile_id' => $profile->id,
            'queue_cycle_id' => $cycle->id,
            'ticket_sequence' => 1,
            'ticket_number' => 'Q001',
            'queue_date' => now()->toDateString(),
            'status' => QueueTicketStatus::Waiting->value,
            'priority' => QueueTicketPriority::Regular->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->rollbackThrough(self::MIGRATION);

        $this->assertFalse(Schema::hasColumn('queue_tickets', 'student_profile_id'));
        $this->assertFalse(Schema::hasColumn('queue_tickets', 'public_token_hash'));
        $this->assertFalse(Schema::hasColumn('student_profiles', 'freshman_payment_confirmed_at'));
        $this->assertSame(0, DB::table('queue_tickets')->count());

        // The DDL committed the test transaction, so put the schema back for the tests that follow.
        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('queue_tickets', 'student_profile_id'));
    }

    private function studentProfile(): StudentProfile
    {
        $program = Program::create(['code' => 'BSIT-MIG', 'name' => 'BS Information Technology', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id, 'name' => 'BSIT Curriculum',
            'effective_school_year' => '2026-2027', 'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Migration Student', 'email' => 'migration.student@grc.test',
            'password' => 'correct-horse-battery-staple', 'role' => UserRole::Student, 'status' => UserStatus::Disabled,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id, 'student_number' => '2026-10-00001',
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted, 'academic_standing' => AcademicStanding::Good,
        ]);
    }
}

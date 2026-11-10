<?php

namespace Tests\Feature\Actions\Identity;

use App\Actions\Identity\AssignStudentNumber;
use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class AssignStudentNumberTest extends TestCase
{
    use RefreshDatabase;

    private function manila(string $dateTime): void
    {
        $this->travelTo(Carbon::parse($dateTime, 'Asia/Manila'));
    }

    private function profileWithNumber(string $studentNumber): StudentProfile
    {
        $program = Program::query()->first()
            ?? Program::create(['code' => 'BSCS', 'name' => 'BS Computer Science', 'status' => ProgramStatus::Active]);
        $curriculum = Curriculum::query()->first()
            ?? Curriculum::create([
                'program_id' => $program->id, 'name' => 'BSCS Curriculum',
                'effective_school_year' => '2026-2027', 'effective_start_year' => 2026,
                'effective_end_year' => 2030, 'status' => CurriculumStatus::Active,
            ]);
        $user = User::create([
            'name' => 'Seed '.$studentNumber,
            'email' => "seed.{$studentNumber}@grc.test",
            'password' => 'irrelevant-password',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id, 'student_number' => $studentNumber,
            'program_id' => $program->id, 'curriculum_id' => $curriculum->id, 'year_level' => 1,
            'admission_status' => 'admitted', 'academic_standing' => 'good',
        ]);
    }

    public function test_the_first_number_of_a_year_is_00001_and_each_call_adds_one(): void
    {
        $this->manila('2027-08-15 10:00:00');
        $action = app(AssignStudentNumber::class);

        self::assertSame('2027-08-00001', $action->handle());
        self::assertSame('2027-08-00002', $action->handle());
        $this->assertDatabaseHas('student_number_sequences', ['year' => 2027, 'last_value' => 2]);
    }

    public function test_a_year_with_no_counter_row_continues_after_the_highest_existing_number(): void
    {
        // A restored dump: students exist but the counter table has no row for the year.
        $this->profileWithNumber('2027-06-01001');
        $this->profileWithNumber('2027-06-01930');
        $this->profileWithNumber('HIST-2027-99999');
        $this->profileWithNumber('TEST-REG-Y1-01');
        $this->profileWithNumber('2026-06-05000');
        $this->manila('2027-08-15 10:00:00');

        self::assertSame('2027-08-01931', app(AssignStudentNumber::class)->handle());
    }

    public function test_the_counter_continues_when_the_month_changes(): void
    {
        $action = app(AssignStudentNumber::class);

        $this->manila('2027-08-15 10:00:00');
        self::assertSame('2027-08-00001', $action->handle());

        $this->manila('2027-09-02 09:00:00');
        self::assertSame('2027-09-00002', $action->handle());
    }

    public function test_a_new_year_restarts_at_00001(): void
    {
        $action = app(AssignStudentNumber::class);

        $this->manila('2027-12-31 23:30:00');
        self::assertSame('2027-12-00001', $action->handle());
        self::assertSame('2027-12-00002', $action->handle());

        $this->manila('2028-01-01 00:30:00');
        self::assertSame('2028-01-00001', $action->handle());
    }

    public function test_the_month_follows_the_manila_calendar_not_utc(): void
    {
        // 17:30 UTC on 31 Aug is 01:30 on 1 Sep in Manila.
        $this->travelTo(Carbon::parse('2027-08-31 17:30:00', 'UTC'));

        self::assertSame('2027-09-00001', app(AssignStudentNumber::class)->handle());
    }

    public function test_a_suffix_already_used_in_another_month_of_the_year_is_skipped(): void
    {
        DB::table('student_number_sequences')->insert([
            'year' => 2027, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->profileWithNumber('2027-05-00002');
        $this->manila('2027-08-15 10:00:00');
        $action = app(AssignStudentNumber::class);

        self::assertSame('2027-08-00001', $action->handle());
        self::assertSame('2027-08-00003', $action->handle());
        $this->assertDatabaseHas('student_number_sequences', ['year' => 2027, 'last_value' => 3]);
    }

    public function test_a_year_with_every_number_used_fails_cleanly_and_leaves_the_counter_alone(): void
    {
        DB::table('student_number_sequences')->insert([
            'year' => 2027, 'last_value' => 99999, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->manila('2027-08-15 10:00:00');

        try {
            app(AssignStudentNumber::class)->handle();
            self::fail('A year with all 99999 numbers used must not hand out a sixth digit.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('student_number', $exception->errors());
        }

        $this->assertDatabaseHas('student_number_sequences', ['year' => 2027, 'last_value' => 99999]);
    }

    public function test_a_failed_outer_transaction_gives_the_number_back(): void
    {
        $this->manila('2027-08-15 10:00:00');
        $action = app(AssignStudentNumber::class);

        try {
            DB::transaction(function () use ($action): void {
                $action->handle();
                throw new RuntimeException('Provisioning failed after the number was taken.');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('student_number_sequences', 0);
        self::assertSame('2027-08-00001', $action->handle());
    }
}

<?php

namespace Tests\Feature\Database;

use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Program;
use Database\Seeders\AcademicTermSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_seeder_creates_the_expected_synthetic_catalog(): void
    {
        $this->seed(ProgramSeeder::class);

        // 12 real GRC programs (BSIT, BEED, BSED-FIL, BSED-ENG,
        // BSED-SOCSCI, BSED-VAL, TCP, BSBA-FM, BSENTREP, BSBA-MM,
        // BSBA-HRM, BSA) plus the one collegeless test fixture (BSCRIM).
        $this->assertDatabaseCount('programs', 13);
        $this->assertSame(
            ProgramStatus::Inactive,
            Program::where('code', 'BSCRIM')->sole()->status,
        );
    }

    /**
     * Eighteen archived historical semesters (2017-2018 through 2025-2026) and
     * one closed current term (2026-2027 1st) tracked by
     * `academic_term_current_slots` — see `AcademicTermSeederTest` for the
     * dedicated, exhaustive coverage of this seeder's shape. This test just
     * confirms `ReferenceDataSeederTest`'s own combined-seed expectations
     * still line up with it.
     */
    public function test_academic_term_seeder_creates_the_expected_terms(): void
    {
        $this->seed(AcademicTermSeeder::class);

        $expected = [];
        foreach (range(2017, 2025) as $startYear) {
            $schoolYear = $startYear.'-'.($startYear + 1);
            $expected[] = "{$schoolYear}|1st";
            $expected[] = "{$schoolYear}|2nd";
        }
        $expected[] = '2026-2027|1st';

        $this->assertDatabaseCount('academic_terms', 19);
        $this->assertSame(18, AcademicTerm::where('status', AcademicTermStatus::Archived)->count());
        $this->assertSame(1, AcademicTerm::where('status', AcademicTermStatus::SemesterClosed)->count());
        $this->assertSame(0, AcademicTerm::where('status', AcademicTermStatus::SemesterOngoing)->count());
        $this->assertSame(
            $expected,
            AcademicTerm::query()->orderBy('school_year')->orderBy('semester')->get()
                ->map(fn (AcademicTerm $term): string => "{$term->school_year}|{$term->semester}")
                ->all(),
        );

        $current = AcademicTerm::where('status', AcademicTermStatus::SemesterClosed)->sole();
        $slot = DB::table('academic_term_current_slots')->where('id', 1)->first();
        $this->assertSame($current->id, $slot->academic_term_id);
    }

    public function test_reseeding_programs_updates_in_place_without_duplicates(): void
    {
        $this->seed(ProgramSeeder::class);
        $originalIds = Program::orderBy('id')->pluck('id')->all();

        $this->seed(ProgramSeeder::class);

        $this->assertSame(13, Program::count());
        $this->assertSame($originalIds, Program::orderBy('id')->pluck('id')->all());
    }

    public function test_reseeding_academic_terms_updates_in_place_without_duplicates(): void
    {
        $this->seed(AcademicTermSeeder::class);
        $originalIds = AcademicTerm::orderBy('id')->pluck('id')->all();

        $this->seed(AcademicTermSeeder::class);

        $this->assertSame(19, AcademicTerm::count());
        $this->assertSame($originalIds, AcademicTerm::orderBy('id')->pluck('id')->all());
    }

    /**
     * Invoked directly rather than through `db:seed`, because the artisan
     * command's own production confirmation prompt would intercept the call
     * before the seeder runs — see RoleUserSeederTest for the same pattern.
     */
    public function test_program_seeder_refuses_to_run_outside_local_and_testing(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        app(ProgramSeeder::class)->run();
    }

    public function test_academic_term_seeder_refuses_to_run_outside_local_and_testing(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        app(AcademicTermSeeder::class)->run();
    }
}

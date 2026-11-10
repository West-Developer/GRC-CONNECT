<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class CurriculumVersioningMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    public function test_curricula_table_gains_the_effective_year_range_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('curricula', [
            'effective_start_year', 'effective_end_year',
        ]));
    }

    public function test_student_profiles_table_gains_the_entry_year_column(): void
    {
        $this->assertTrue(Schema::hasColumn('student_profiles', 'entry_year'));
    }

    public function test_backfill_parses_the_existing_effective_school_year_string(): void
    {
        $programId = DB::table('programs')->insertGetId([
            'code' => 'BSIT', 'name' => 'BS IT', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $curriculumId = DB::table('curricula')->insertGetId([
            'program_id' => $programId, 'name' => 'BSIT 2024-2029 Curriculum',
            'effective_school_year' => '2024-2029', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Eight later migrations currently follow the effective-year
        // migration. Roll back through it so rerunning the migration executes
        // the backfill against the inserted legacy row.
        $this->rollbackThrough('2026_08_07_000001_add_effective_year_range_to_curricula');
        $this->artisan('migrate')->assertExitCode(0);

        $row = DB::table('curricula')->where('id', $curriculumId)->first();
        $this->assertSame(2024, $row->effective_start_year);
        $this->assertSame(2029, $row->effective_end_year);
    }

    public function test_a_program_cannot_hold_two_curricula_with_the_same_start_year(): void
    {
        $programId = DB::table('programs')->insertGetId([
            'code' => 'BSIT', 'name' => 'BS IT', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('curricula')->insert([
            'program_id' => $programId, 'name' => 'BSIT 2024-2029 Curriculum',
            'effective_school_year' => '2024-2029', 'effective_start_year' => 2024,
            'effective_end_year' => 2029, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('curricula')->insert([
            'program_id' => $programId, 'name' => 'BSIT 2024-2029 Curriculum (duplicate)',
            'effective_school_year' => '2024-2029', 'effective_start_year' => 2024,
            'effective_end_year' => 2029, 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_migrations_are_fully_reversible(): void
    {
        // --step 9 -- see test_backfill_parses_the_existing_effective_school_year_string.
        $this->rollbackThrough('2026_08_07_000001_add_effective_year_range_to_curricula');

        $this->assertFalse(Schema::hasColumn('curricula', 'effective_start_year'));
        $this->assertFalse(Schema::hasColumn('student_profiles', 'entry_year'));

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('curricula', 'effective_start_year'));
        $this->assertTrue(Schema::hasColumn('student_profiles', 'entry_year'));
    }
}

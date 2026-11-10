<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class NormalizeStudentNameCasingMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    public function test_the_migration_normalizes_casing_for_existing_student_rows_only(): void
    {
        $this->rollbackThrough('2026_08_27_000003_normalize_student_name_casing');

        $student = DB::table('users')->insertGetId([
            'name' => 'JUAN M. DELA CRUZ III',
            'first_name' => 'JUAN',
            'middle_initial' => 'm',
            'last_name' => 'DELA CRUZ',
            'suffix' => 'iii',
            'email' => 'student.casing@grc.test',
            'password' => 'irrelevant-hash',
            'role' => 'student',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $facultyUntouched = DB::table('users')->insertGetId([
            'name' => 'REYES, MARIA',
            'first_name' => 'MARIA',
            'middle_initial' => null,
            'last_name' => 'REYES',
            'suffix' => null,
            'email' => 'faculty.casing@grc.test',
            'password' => 'irrelevant-hash',
            'role' => 'faculty',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate')->assertExitCode(0);

        $row = fn (int $id) => DB::table('users')->where('id', $id)->first();

        $normalized = $row($student);
        self::assertSame('Juan', $normalized->first_name);
        self::assertSame('M', $normalized->middle_initial);
        self::assertSame('Dela Cruz', $normalized->last_name);
        self::assertSame('III', $normalized->suffix);
        self::assertSame('Juan M. Dela Cruz III', $normalized->name);

        $untouched = $row($facultyUntouched);
        self::assertSame('MARIA', $untouched->first_name);
        self::assertSame('REYES', $untouched->last_name);
        self::assertSame('REYES, MARIA', $untouched->name, 'only role=student rows are backfilled');
    }
}

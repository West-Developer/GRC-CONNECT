<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class EnrollmentDeadlineMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    private const MIGRATION = '2026_10_14_000001_add_enrollment_deadline_at_to_student_profiles_table';

    public function test_the_deadline_column_exists_and_rolls_back(): void
    {
        $this->assertTrue(Schema::hasColumn('student_profiles', 'enrollment_deadline_at'));

        $this->rollbackThrough(self::MIGRATION);

        $this->assertFalse(Schema::hasColumn('student_profiles', 'enrollment_deadline_at'));
    }
}

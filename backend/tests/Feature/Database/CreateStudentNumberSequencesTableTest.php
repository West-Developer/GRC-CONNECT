<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class CreateStudentNumberSequencesTableTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    public function test_it_creates_and_removes_the_student_number_sequences_table(): void
    {
        $this->assertTrue(Schema::hasTable('student_number_sequences'));
        $this->assertTrue(Schema::hasColumns('student_number_sequences', ['year', 'last_value', 'created_at', 'updated_at']));

        $this->rollbackThrough('2026_10_04_000001_create_student_number_sequences_table');

        $this->assertFalse(Schema::hasTable('student_number_sequences'));

        // The DDL committed the test transaction, so put the schema back for the tests that follow.
        $this->artisan('migrate')->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('student_number_sequences'));
    }
}

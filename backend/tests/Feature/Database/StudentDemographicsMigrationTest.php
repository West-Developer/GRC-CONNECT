<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class StudentDemographicsMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    private const MIGRATION = '2026_10_15_000001_add_demographics_to_student_profiles_table';

    private const COLUMNS = [
        'gender',
        'birth_date',
        'citizenship',
        'contact_number',
        'street_barangay',
        'town_city',
        'province',
        'demographics_demo_fields',
    ];

    public function test_the_demographics_columns_exist_and_roll_back(): void
    {
        foreach (self::COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('student_profiles', $column), "{$column} is missing");
        }

        $this->rollbackThrough(self::MIGRATION);

        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('student_profiles', $column), "{$column} is still there");
        }
    }
}

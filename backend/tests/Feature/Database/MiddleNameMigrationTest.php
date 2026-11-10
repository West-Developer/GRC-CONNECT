<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class MiddleNameMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    private const MIGRATION = '2026_10_16_000001_add_middle_name_to_users_and_profile_change_requests';

    public function test_the_middle_name_columns_exist_and_roll_back(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'middle_name'));
        $this->assertTrue(Schema::hasColumn('student_profile_change_requests', 'requested_middle_name'));
        // The initial stays: it is what every display of the name uses.
        $this->assertTrue(Schema::hasColumn('users', 'middle_initial'));

        $this->rollbackThrough(self::MIGRATION);

        $this->assertFalse(Schema::hasColumn('users', 'middle_name'));
        $this->assertFalse(Schema::hasColumn('student_profile_change_requests', 'requested_middle_name'));
        $this->assertTrue(Schema::hasColumn('users', 'middle_initial'));
    }
}

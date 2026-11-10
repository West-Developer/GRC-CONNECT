<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class UsersArchivedAtMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    private const MIGRATION = '2026_10_16_000002_add_archived_at_to_users_table';

    public function test_the_archived_at_column_exists_and_rolls_back(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'archived_at'));

        $this->rollbackThrough(self::MIGRATION);

        $this->assertFalse(Schema::hasColumn('users', 'archived_at'));
    }
}

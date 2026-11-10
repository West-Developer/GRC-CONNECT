<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class AddActingContextToPersonalAccessTokensTableTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    public function test_it_adds_and_removes_the_acting_context_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'acting_role'));
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'acting_college'));

        $this->rollbackThrough('2026_10_01_000001_add_acting_context_to_personal_access_tokens_table');

        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'acting_role'));
        $this->assertFalse(Schema::hasColumn('personal_access_tokens', 'acting_college'));
    }
}

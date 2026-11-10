<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class PrivacyNoticeAcceptancesMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    private const MIGRATION = '2026_10_18_000001_create_privacy_notice_acceptances_table';

    public function test_the_acceptances_table_exists_and_rolls_back(): void
    {
        $this->assertTrue(Schema::hasTable('privacy_notice_acceptances'));
        $this->assertTrue(Schema::hasColumns('privacy_notice_acceptances', [
            'user_id', 'notice_version', 'method', 'recorded_by_user_id', 'accepted_at',
        ]));

        $this->rollbackThrough(self::MIGRATION);

        $this->assertFalse(Schema::hasTable('privacy_notice_acceptances'));
        $this->assertTrue(Schema::hasTable('users'));
    }
}

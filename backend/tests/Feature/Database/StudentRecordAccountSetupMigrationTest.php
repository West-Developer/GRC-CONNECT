<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class StudentRecordAccountSetupMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    /**
     * A pre-existing account (created before invitation-based provisioning
     * shipped) must remain usable: it already has a real password, so the
     * migration backfills `account_setup_completed_at` from its own
     * `created_at` rather than leaving it null and disabled-looking.
     *
     * Reapplies through `artisan('migrate')` rather than calling the
     * migration's `up()` directly: a direct call would leave the
     * `migrations` bookkeeping table missing rows for the rolled-back
     * migrations even though their schema changes are back in place, which
     * corrupts any later reversibility test sharing this database
     * connection within the same test run.
     *
     * `--step=7`: must rollback past all migrations from 2026_08_29_000001 (new
     * migration) through 2026_08_26_000002 to get before 2026_08_26_000001
     * which adds the `account_setup_completed_at` column.
     */
    public function test_the_migration_backfills_completed_setup_for_pre_existing_accounts(): void
    {
        $this->rollbackThrough('2026_08_26_000001_add_student_record_and_account_setup_fields');
        self::assertFalse(Schema::hasColumn('users', 'account_setup_completed_at'));

        DB::table('users')->insert([
            'name' => 'Legacy Faculty',
            'email' => 'legacy.migration@grc.test',
            'password' => 'irrelevant-hash',
            'role' => 'faculty',
            'status' => 'active',
            'created_at' => '2020-01-15 08:00:00',
            'updated_at' => '2020-01-15 08:00:00',
        ]);

        $this->artisan('migrate')->assertExitCode(0);

        self::assertTrue(Schema::hasColumn('users', 'account_setup_completed_at'));
        self::assertSame(
            '2020-01-15 08:00:00',
            DB::table('users')->where('email', 'legacy.migration@grc.test')->value('account_setup_completed_at'),
            'A pre-existing account must be backfilled as already set up, using its own created_at.',
        );
    }
}

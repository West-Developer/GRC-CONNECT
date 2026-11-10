<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Rolls the database back far enough that a named migration is undone too,
 * however many migrations were added after it. The old `migrate:rollback
 * --step=N` tests hard-coded N, so every new migration silently broke them.
 * The order matches what `migrate:rollback` itself uses (batch, then name,
 * newest first).
 */
trait RollsBackThroughMigration
{
    protected function rollbackThrough(string $migration): void
    {
        $order = DB::table('migrations')
            ->orderByDesc('batch')
            ->orderByDesc('migration')
            ->pluck('migration')
            ->all();

        $position = array_search($migration, $order, true);

        $this->assertNotFalse($position, "Migration {$migration} has not been run.");

        $this->artisan('migrate:rollback', ['--step' => $position + 1])->assertExitCode(0);
    }
}

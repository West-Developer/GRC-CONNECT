<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RollsBackThroughMigration;
use Tests\TestCase;

final class AddSubmissionAndReturnToTransfereeCreditsTableTest extends TestCase
{
    use RefreshDatabase;
    use RollsBackThroughMigration;

    private const MIGRATION = '2026_10_04_000004_add_submission_and_return_to_transferee_credits_table';

    private const COLUMNS = ['submitted_at', 'returned_by', 'returned_at', 'return_reason'];

    public function test_it_adds_and_removes_the_submission_and_return_columns(): void
    {
        foreach (self::COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('transferee_credits', $column), "Missing {$column}.");
        }

        $this->rollbackThrough(self::MIGRATION);

        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('transferee_credits', $column), "{$column} was not dropped.");
        }
    }
}

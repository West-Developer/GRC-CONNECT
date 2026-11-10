<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The composite performance indexes lead with a foreign-key column, so
 * creating them makes InnoDB drop the FK's own implicit index. `down()` must
 * put a plain index back BEFORE dropping the composite, otherwise MariaDB
 * refuses with 1553 and the rollback stops half-way.
 *
 * The migration is selected by `--path`, not `--step=N`, so this test does not
 * go stale every time a newer migration lands on top of it.
 */
final class PerformanceIndexesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_25_000001_add_performance_indexes.php';

    /** @var array<string, array{index: string, column: string}> */
    private const INDEXES = [
        'enrollments' => ['index' => 'enrollments_term_status_submitted_at_idx', 'column' => 'academic_term_id'],
        'queue_tickets' => ['index' => 'queue_tickets_cycle_status_priority_idx', 'column' => 'queue_cycle_id'],
        'enrollment_subjects' => ['index' => 'enrollment_subjects_section_status_idx', 'column' => 'section_id'],
        'academic_grades' => ['index' => 'academic_grades_term_status_idx', 'column' => 'academic_term_id'],
    ];

    public function test_the_composite_indexes_exist_after_migrating(): void
    {
        foreach (self::INDEXES as $table => $definition) {
            self::assertTrue(
                Schema::hasIndex($table, $definition['index']),
                "{$table}.{$definition['index']} is missing after migrate.",
            );
        }
    }

    public function test_it_rolls_back_cleanly_keeps_every_foreign_key_column_indexed_and_re_applies(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertExitCode(0);

        foreach (self::INDEXES as $table => $definition) {
            self::assertFalse(
                Schema::hasIndex($table, $definition['index']),
                "{$table}.{$definition['index']} survived the rollback.",
            );
            self::assertTrue(
                $this->someIndexLeadsWith($table, $definition['column']),
                "{$table}.{$definition['column']} lost the index its foreign key relies on.",
            );
        }

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertExitCode(0);

        foreach (self::INDEXES as $table => $definition) {
            self::assertTrue(
                Schema::hasIndex($table, $definition['index']),
                "{$table}.{$definition['index']} was not re-created by migrating again.",
            );
        }
    }

    /**
     * A plain index or a wider one (e.g. a unique on `(queue_cycle_id,
     * ticket_sequence)`) both keep the foreign key supported.
     */
    private function someIndexLeadsWith(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}

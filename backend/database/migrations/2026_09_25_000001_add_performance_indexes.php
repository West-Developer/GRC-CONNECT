<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes (Slice 4, ADR 0029 / docs/runbooks/performance-deployment.md).
 *
 * Adds four composite indexes identified as missing by the Slice-4 audit:
 *
 *  1. enrollments(academic_term_id, status, submitted_at) — the
 *     registrar/accounting enrollment list is always filtered by term and
 *     status, then sorted by submission time (MariaDB 10.4 has no descending
 *     indexes; it reads this one backwards for `ORDER BY submitted_at DESC`).
 *
 *  2. queue_tickets(queue_cycle_id, status, priority) — the queue-kiosk and
 *     live-queue views filter by cycle and status, then sort by priority.
 *
 *  3. enrollment_subjects(section_id, status) — enrollment subject lookups
 *     by section and status (enrolled/dropped/pending) drive seat counts and
 *     the section detail panel.
 *
 *  4. academic_grades(academic_term_id, status) — the grade-listing endpoint
 *     filters by term and lock-status on every list call.
 *
 * Reversible: down() drops all four in the reverse order. Every composite index
 * leads with a foreign-key column, and creating it makes InnoDB silently drop the
 * FK's own implicit single-column index, so the composite becomes the only index
 * the constraint can rely on. down() therefore gives the FK a plain index again
 * BEFORE dropping the composite; otherwise MariaDB refuses with
 * "1553 Cannot drop index ... needed in a foreign key constraint", the rollback
 * stops half-way and every later `migrate:rollback --step=N` test inherits a
 * broken schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            // Named indexes so down() mirrors up() unambiguously.
            $table->index(
                ['academic_term_id', 'status', 'submitted_at'],
                'enrollments_term_status_submitted_at_idx'
            );
        });

        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->index(
                ['queue_cycle_id', 'status', 'priority'],
                'queue_tickets_cycle_status_priority_idx'
            );
        });

        Schema::table('enrollment_subjects', function (Blueprint $table): void {
            $table->index(
                ['section_id', 'status'],
                'enrollment_subjects_section_status_idx'
            );
        });

        Schema::table('academic_grades', function (Blueprint $table): void {
            $table->index(
                ['academic_term_id', 'status'],
                'academic_grades_term_status_idx'
            );
        });
    }

    public function down(): void
    {
        $this->dropCompositeIndex('academic_grades', 'academic_grades_term_status_idx', 'academic_term_id');
        $this->dropCompositeIndex('enrollment_subjects', 'enrollment_subjects_section_status_idx', 'section_id');
        $this->dropCompositeIndex('queue_tickets', 'queue_tickets_cycle_status_priority_idx', 'queue_cycle_id');
        $this->dropCompositeIndex('enrollments', 'enrollments_term_status_submitted_at_idx', 'academic_term_id');
    }

    /**
     * Drops $index, first re-creating a plain index on $leadingColumn when a
     * foreign key on that column would otherwise be left without one.
     */
    private function dropCompositeIndex(string $tableName, string $index, string $leadingColumn): void
    {
        if (! $this->hasForeignKeyOn($tableName, $leadingColumn)
            || $this->hasOtherIndexLeadingWith($tableName, $index, $leadingColumn)) {
            Schema::table($tableName, function (Blueprint $table) use ($index): void {
                $table->dropIndex($index);
            });

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($leadingColumn): void {
            $table->index($leadingColumn, $table->getTable().'_'.$leadingColumn.'_index');
        });

        Schema::table($tableName, function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });
    }

    private function hasForeignKeyOn(string $tableName, string $column): bool
    {
        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (($foreignKey['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    private function hasOtherIndexLeadingWith(string $tableName, string $ignoredIndex, string $column): bool
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if ($index['name'] !== $ignoredIndex && ($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
};

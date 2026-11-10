<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GRC replaces its curriculum roughly every 5 years, and a student's
 * curriculum version never changes after enrollment -- a 2023-entry student
 * stays on the curriculum that was current when they entered even after a
 * newer one is authored. `effective_school_year` (e.g. "2024-2029") is a
 * free-text display label; nothing before this migration could resolve
 * "the curriculum in effect for entry year N" as a query, since the pair is
 * embedded in a string rather than compared as integers.
 *
 * Both columns are nullable: a curriculum still being authored may not have
 * its year range settled yet (see CurriculumStatus::Draft). The backfill
 * below only ever narrows that nullability for existing rows whose
 * `effective_school_year` already parses as "YYYY-YYYY" -- it never
 * invents a range for a row it can't parse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curricula', function (Blueprint $table): void {
            $table->unsignedSmallInteger('effective_start_year')->nullable()->after('effective_school_year');
            $table->unsignedSmallInteger('effective_end_year')->nullable()->after('effective_start_year');
        });

        DB::table('curricula')->orderBy('id')->select('id', 'effective_school_year')
            ->each(function (object $row): void {
                if (! preg_match('/^(\d{4})\s*-\s*(\d{4})$/', trim($row->effective_school_year), $matches)) {
                    return;
                }

                DB::table('curricula')->where('id', $row->id)->update([
                    'effective_start_year' => (int) $matches[1],
                    'effective_end_year' => (int) $matches[2],
                ]);
            });

        Schema::table('curricula', function (Blueprint $table): void {
            $table->unique(['program_id', 'effective_start_year']);
        });
    }

    public function down(): void
    {
        // Adding the composite unique below let MariaDB retire the
        // auto-generated single-column index it had created to support the
        // `program_id` foreign key, since the composite index's leftmost
        // column covers it too. Restore a plain index first so the foreign
        // key keeps a supporting index once the composite unique is gone.
        Schema::table('curricula', function (Blueprint $table): void {
            $table->index('program_id');
        });

        Schema::table('curricula', function (Blueprint $table): void {
            $table->dropUnique(['program_id', 'effective_start_year']);
            $table->dropColumn(['effective_start_year', 'effective_end_year']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per `App\Domain\Enrollment\EnrollmentAudience` case (year_1..4,
 * irregular) per term — five rows, not four. `audience` is a string rather
 * than a nullable `year_level` so the uniqueness constraint stays a plain
 * two-column unique index; a nullable `year_level` would need a generated
 * sentinel to make "one irregular row per term" enforceable the same way.
 *
 * This migration has never been applied to any database (see PROGRESS.md's
 * 2026-08-03 MariaDB CREATE-privilege entry), so it is safe to redesign in
 * place rather than layering a second migration on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_term_enrollment_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->cascadeOnDelete();
            $table->string('audience');
            $table->dateTime('opens_at')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->timestamps();

            // Explicit index names: the table name alone is 32 characters, so
            // Laravel's generated `<table>_<col>_<col>_unique` would be 65 —
            // one over MySQL's 64-character identifier limit — and the
            // `_index` counterpart would land exactly on 64.
            $table->unique(['academic_term_id', 'audience'], 'atew_term_audience_unique');
            $table->index(['academic_term_id', 'opens_at'], 'atew_term_opens_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_term_enrollment_windows');
    }
};

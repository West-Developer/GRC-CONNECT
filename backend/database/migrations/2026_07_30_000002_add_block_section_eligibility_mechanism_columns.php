<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-ENR-011: "Enforce approved block-section eligibility rules, including
 * preventing irregular students from reserving slots designated exclusively
 * for regular block students."
 *
 * Both columns are nullable with NO default, on purpose — the same
 * mechanism-implemented/value-flagged pattern already used for
 * `sections.viability_threshold` (see that migration's docblock). PRD §17
 * lists neither a block-exclusive section flag nor a regular/irregular
 * student classification as a confirmed institutional rule; inventing an
 * enum here (e.g. hardcoding "regular"/"irregular" as the only two
 * categories) would assert a taxonomy GRC has not approved. A bare nullable
 * string leaves that vocabulary open. The eligible-subject pool (Phase 6
 * Task 4) enforces the block-exclusive exclusion only when a section's
 * `is_block_exclusive` is true AND the enrolling student's
 * `enrollment_category` is non-null and does not match — otherwise the rule
 * is a no-op, exactly as it is today for every unconfigured `academic_grades`
 * or `sections` column left NULL by an unconfirmed §17 decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->boolean('is_block_exclusive')->nullable()->after('viability_threshold');
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('enrollment_category')->nullable()->after('year_level');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('is_block_exclusive');
        });

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('enrollment_category');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Program Chair's per-year-level class capacity: how many students each
 * generated block of that year level seats.
 *
 * The default of 40 is not a policy choice — it is exactly the literal
 * `SaveSectionPlan::release()` already hardcoded as its capacity fallback,
 * so existing plans keep producing identical sections and this migration
 * changes no observable behavior on its own.
 *
 * Capacity lives here rather than on `subject_offerings` because the Chair
 * plans by block ("2nd year blocks seat 38"), not per subject; the
 * generated `sections.capacity` is what the enrollment pool actually reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_term_section_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('students_per_block')->default(40)->after('section_count');
        });
    }

    public function down(): void
    {
        Schema::table('academic_term_section_plans', function (Blueprint $table) {
            $table->dropColumn('students_per_block');
        });
    }
};

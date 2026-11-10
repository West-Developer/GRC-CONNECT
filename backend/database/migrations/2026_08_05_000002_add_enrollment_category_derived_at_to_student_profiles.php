<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when `student_profiles.enrollment_category` was last set by
 * `App\Actions\Academic\ReclassifyStudentEnrollmentCategory` (automatic,
 * grade-driven derivation — see ADR 0021), as opposed to the provisioning
 * default `ProvisionStudent` writes at admission time. Without this
 * timestamp there is no way to tell a freshly-provisioned "regular" default
 * apart from a genuinely re-derived one, no "as of" display is possible,
 * and re-running the backfill command is undebuggable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->timestamp('enrollment_category_derived_at')->nullable()->after('enrollment_category');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('enrollment_category_derived_at');
        });
    }
};

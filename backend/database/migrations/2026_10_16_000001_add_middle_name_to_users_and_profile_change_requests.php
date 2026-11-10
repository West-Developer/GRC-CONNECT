<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's full middle name (ADR 0053). Admission types it; `middle_initial` is derived from it and
 * stays what every screen, list and document shows ("West R. Ragma"), so only Admission and the student on
 * their own profile ever see the full name. Both columns are nullable and nothing is backfilled: an older
 * account has only an initial and the full name is not known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('middle_name', 100)->nullable()->after('first_name');
        });

        Schema::table('student_profile_change_requests', function (Blueprint $table): void {
            $table->string('requested_middle_name', 100)->nullable()->after('requested_first_name');
        });
    }

    public function down(): void
    {
        Schema::table('student_profile_change_requests', function (Blueprint $table): void {
            $table->dropColumn('requested_middle_name');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('middle_name');
        });
    }
};

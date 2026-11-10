<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds college scoping ahead of the four-college subjects/prerequisites
     * import. `subjects.code` was globally unique; the real source catalog
     * has 41 codes legitimately repeated across colleges (shared general-ed
     * subjects), so uniqueness moves to the (college, code) pair. Schema-only
     * — existing rows are backfilled by their own seeders, not here.
     */
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('college')->nullable()->after('code');
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['college', 'code']);
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->string('college')->nullable()->after('code');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('college')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['college', 'code']);
            $table->unique('code');
            $table->dropColumn('college');
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('college');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('college');
        });
    }
};

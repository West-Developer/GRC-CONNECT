<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit mapping redesign (ADR 0026). A credit can now be asked for by the
 * Student, mapped and endorsed by the Program Chair, and only then approved by
 * Registrar Staff, so the table needs to say who asked (`requested_by`) and
 * who endorsed it and when. `TransfereeCreditStatus` gains `endorsed`; that
 * column is a plain string, so no change is needed for it.
 *
 * `credited_units` was `unsignedTinyInteger`, which cannot hold a 1.5-unit
 * subject (a student may enter one, and `subjects.units` already went
 * fractional in `2026_07_30_000001`). Widening is additive: whole numbers
 * round-trip unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transferee_credits', function (Blueprint $table) {
            $table->foreignId('requested_by')->nullable()->after('subject_id')->constrained('users')->nullOnDelete();
            $table->foreignId('endorsed_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->timestamp('endorsed_at')->nullable()->after('endorsed_by');
        });

        Schema::table('transferee_credits', function (Blueprint $table) {
            $table->decimal('credited_units', 4, 1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('transferee_credits', function (Blueprint $table) {
            $table->unsignedTinyInteger('credited_units')->change();
        });

        Schema::table('transferee_credits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('endorsed_by');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn('endorsed_at');
        });
    }
};

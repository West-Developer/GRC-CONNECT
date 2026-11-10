<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A professor's self-declared specialization now requires Program Chair
 * approval before it counts as a real capability signal (see
 * docs/superpowers/specs/2026-08-29-faculty-workforce-page-and-specialization-approval-design.md).
 * Existing rows default to `approved` so nothing the recommendation engine
 * already reads silently disappears behind the new gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculty_specializations', function (Blueprint $table) {
            $table->string('status', 16)->default('approved')->after('source');
            $table->foreignId('decided_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->text('decision_reason')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('faculty_specializations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['status', 'decided_at', 'decision_reason']);
        });
    }
};

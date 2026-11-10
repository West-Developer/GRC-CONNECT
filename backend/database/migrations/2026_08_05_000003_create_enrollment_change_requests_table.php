<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 add/drop/change-section requests — the add/drop-window
 * counterpart to `withdrawal_requests`. `reason` is NOT NULL per the
 * explicit user requirement that every request states why. Unlike
 * `withdrawal_requests` (whose processor's reason lives only in the audit
 * log), `decision_reason` is a real column here so Registrar Staff's
 * read-only view can show the decision context without audit-log access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_section_id')->nullable()->constrained('sections')->restrictOnDelete();
            $table->foreignId('to_section_id')->nullable()->constrained('sections')->restrictOnDelete();
            $table->text('reason');
            $table->string('status');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_change_requests');
    }
};

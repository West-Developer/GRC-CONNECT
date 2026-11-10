<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Once a section is published a Program Head can no longer edit its schedule
 * directly (stakeholder Doc 14, ADR 0032). The Program Head files a change
 * request instead and the Registrar Head decides it. `old_values` is the
 * section as the requester saw it, so an approval can tell that the section
 * moved in the meantime; `new_values` is only the fields being changed.
 *
 * `dateTime` columns on purpose: MariaDB's legacy `explicit_defaults_for_timestamp
 * = 0` would give a TIMESTAMP column an implicit `ON UPDATE CURRENT_TIMESTAMP`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->json('old_values');
            $table->json('new_values');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->index(['status', 'created_at'], 'section_change_requests_status_created_index');
            $table->index(['section_id', 'status'], 'section_change_requests_section_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_change_requests');
    }
};

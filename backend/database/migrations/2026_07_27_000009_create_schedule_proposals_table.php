<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.2 schedule proposals, following the §4.1 lifecycle
 * draft → dean_approved → executive_approved → published → closed.
 *
 * `decision_reason` is nullable overall but PRD §4.1 requires a reason when a
 * proposal is returned to draft, and §5.1 requires every transition to be
 * audited. Enforcing "reason required on return" is application logic in the
 * approval slice, not a column-level constraint, because the requirement
 * applies to one transition rather than to every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('status');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_proposals');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_control_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('step', 48);
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('warnings')->nullable();
            $table->text('error_summary')->nullable();
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['step', 'status'], 'it_control_run_step_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_control_automation_runs');
    }
};

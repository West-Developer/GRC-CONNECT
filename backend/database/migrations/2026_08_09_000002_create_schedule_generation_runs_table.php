<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('prediction_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('college');
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 50);
            $table->json('warnings')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['academic_term_id', 'college', 'status', 'created_at'],
                'schedule_generation_runs_term_college_status_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_generation_runs');
    }
};

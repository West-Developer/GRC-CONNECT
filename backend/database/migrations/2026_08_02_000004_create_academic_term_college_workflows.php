<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_term_college_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->cascadeOnDelete();
            $table->string('college');
            $table->string('stage')->default('draft');
            $table->foreignId('curriculum_completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('curriculum_completed_at')->nullable();
            $table->foreignId('faculty_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('faculty_reviewed_at')->nullable();
            $table->foreignId('schedule_submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('schedule_submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['academic_term_id', 'college']);
            $table->index(['college', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_term_college_workflows');
    }
};

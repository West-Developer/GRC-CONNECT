<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_migrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->restrictOnDelete();
            $table->foreignId('source_curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->foreignId('target_curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('migrated_at');
            $table->timestamps();
        });

        Schema::create('curriculum_migration_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('curriculum_migration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_subject_equivalency_id');
            $table->foreign('curriculum_subject_equivalency_id', 'migration_credits_equivalency_fk')
                ->references('id')
                ->on('curriculum_subject_equivalencies')
                ->restrictOnDelete();
            $table->foreignId('source_academic_grade_id')->constrained('academic_grades')->restrictOnDelete();
            $table->foreignId('target_subject_id')->constrained('subjects')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['curriculum_migration_id', 'target_subject_id'], 'curriculum_migration_credit_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_migration_credits');
        Schema::dropIfExists('curriculum_migrations');
    }
};

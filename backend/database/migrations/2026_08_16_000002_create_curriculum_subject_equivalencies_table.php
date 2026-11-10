<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_subject_equivalencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->foreignId('target_curriculum_id')->constrained('curricula')->cascadeOnDelete();
            $table->foreignId('source_subject_id')->constrained('subjects')->restrictOnDelete();
            $table->foreignId('target_subject_id')->constrained('subjects')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['source_curriculum_id', 'target_curriculum_id', 'source_subject_id'], 'curriculum_equivalencies_unique_source');
            $table->unique(['source_curriculum_id', 'target_curriculum_id', 'target_subject_id'], 'curriculum_equivalencies_unique_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_subject_equivalencies');
    }
};

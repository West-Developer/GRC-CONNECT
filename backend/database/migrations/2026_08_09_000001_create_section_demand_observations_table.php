<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_demand_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('college');
            $table->unsignedTinyInteger('year_level');
            $table->unsignedSmallInteger('cohort_size');
            $table->unsignedSmallInteger('enrolled_count');
            $table->unsignedSmallInteger('section_count');
            $table->unsignedSmallInteger('offered_capacity');
            $table->string('source', 50);
            $table->timestamps();

            $table->unique(
                ['academic_term_id', 'program_id', 'curriculum_id', 'subject_id', 'year_level'],
                'section_demand_obs_term_curr_subject_year_unique',
            );
            $table->index(
                ['college', 'academic_term_id', 'year_level'],
                'section_demand_observations_college_term_year_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_demand_observations');
    }
};

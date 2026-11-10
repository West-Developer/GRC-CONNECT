<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Process 1.1's only genuinely new data (min/max section capacity, a
     * manually-entered recommended section count) planned per academic term
     * + curriculum + subject — not per `curriculum_subject_id`, because
     * SynchronizeCurriculumSubjects deletes and recreates every placement row
     * on every curriculum save, which would silently destroy anything keyed
     * to that id. `year_level`/`semester` are snapshotted from the
     * curriculum's placement at save time rather than live-joined, for the
     * same reason.
     */
    public function up(): void
    {
        Schema::create('subject_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('curriculum_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('year_level');
            $table->string('semester');
            $table->unsignedSmallInteger('min_section_capacity');
            $table->unsignedSmallInteger('max_section_capacity');
            $table->unsignedSmallInteger('recommended_sections');
            $table->timestamps();

            $table->unique(
                ['academic_term_id', 'curriculum_id', 'subject_id'],
                'subject_offerings_term_curriculum_subject_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_offerings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.2 ranked faculty subject preferences.
 *
 * Two unique constraints, both intentional: a professor may not rank the same
 * subject twice in a term, and may not reuse a rank position within a term.
 * Together they keep the preference list a genuine ordering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_subject_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('professor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rank');
            $table->timestamps();

            $table->unique(
                ['professor_id', 'academic_term_id', 'subject_id'],
                'faculty_preference_unique_subject',
            );
            $table->unique(
                ['professor_id', 'academic_term_id', 'rank'],
                'faculty_preference_unique_rank',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_subject_preferences');
    }
};

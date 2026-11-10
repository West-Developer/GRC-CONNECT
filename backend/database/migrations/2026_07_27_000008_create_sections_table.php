<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.2 planned sections.
 *
 * `viability_threshold` is nullable with NO default on purpose. PRD §4.1
 * mentions 25 students as the currently documented figure, but §17 lists the
 * section-viability threshold and its exception authority as an open GRC
 * decision — so the schema records a per-section value when one is authorized
 * and otherwise stores nothing. Likewise `room` is a free string because §17
 * leaves the room-capacity source and conflict rules unconfirmed.
 *
 * `enrolled_count` is a maintained counter, not a source of truth; the
 * authoritative seat count is the enrollment_subjects rows that occupy a seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('section_code');
            $table->foreignId('professor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('schedule_days')->nullable();
            $table->time('starts_at_time')->nullable();
            $table->time('ends_at_time')->nullable();
            $table->string('room')->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('viability_threshold')->nullable();
            $table->unsignedSmallInteger('enrolled_count')->default(0);
            $table->string('status');
            $table->timestamps();

            $table->unique(
                ['academic_term_id', 'subject_id', 'section_code'],
                'sections_unique_code_per_term_subject',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};

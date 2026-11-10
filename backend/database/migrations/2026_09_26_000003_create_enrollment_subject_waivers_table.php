<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Registrar Head may let one student take one subject in one term even
 * though a prerequisite is not met, including a prerequisite the student failed
 * (stakeholder Doc 14, ADR 0031). This is a per-student, per-subject, per-term
 * exception with a required reason, not a general override of the curriculum:
 * `BuildEligibleSubjectPool` stops excluding the subject for that prerequisite
 * only, and everything else (already completed, no open section, block
 * restrictions) still applies.
 *
 * One row per (student, subject, term). Revoking sets `revoked_at` rather than
 * deleting, so the history stays; granting again re-activates the same row.
 * `dateTime` columns on purpose: MariaDB's legacy `explicit_defaults_for_timestamp
 * = 0` would give a TIMESTAMP column an implicit `ON UPDATE CURRENT_TIMESTAMP`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_subject_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained('academic_terms')->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('granted_by')->constrained('users');
            $table->dateTime('granted_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();

            $table->unique(['student_id', 'subject_id', 'academic_term_id'], 'enrollment_subject_waivers_student_subject_term_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_subject_waivers');
    }
};

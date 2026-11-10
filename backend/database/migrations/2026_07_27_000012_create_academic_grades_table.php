<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.3 academic grades, following the §4.3 lifecycle
 * draft → submitted → locked.
 *
 * `section_id` is nullable because PRD §10.3 requires transferred records to be
 * storable without an owning section.
 *
 * `final_grade` is a bare decimal with NO range or passing-mark constraint:
 * PRD §17 lists the official passing-grade rule, special marks, and equivalent
 * grades as open decisions. Encoding a numeric range here would assert a
 * grading scale GRC has not approved. `remarks` carries special marks until
 * that vocabulary is confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->decimal('final_grade', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->string('status');
            $table->foreignId('encoded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['student_id', 'subject_id', 'academic_term_id'],
                'academic_grades_unique_student_subject_term',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_grades');
    }
};

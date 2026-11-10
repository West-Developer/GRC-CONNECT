<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's shift from one course to another, recorded by the Registrar
 * (stakeholder Doc 14, ADR 0034). It exists so Enrollment Analytics can show
 * "from course, to course" counts; it is a record, not a workflow, and it does
 * not itself change the student's program or curriculum.
 *
 * `dateTime` columns on purpose: MariaDB's legacy `explicit_defaults_for_timestamp
 * = 0` would give a TIMESTAMP column an implicit `ON UPDATE CURRENT_TIMESTAMP`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('from_program_id')->constrained('programs');
            $table->foreignId('to_program_id')->constrained('programs');
            $table->foreignId('academic_term_id')->constrained('academic_terms')->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('recorded_by')->constrained('users');
            $table->dateTime('recorded_at');

            $table->index(['academic_term_id', 'from_program_id', 'to_program_id'], 'program_shifts_term_from_to_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_shifts');
    }
};

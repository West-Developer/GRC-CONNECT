<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faculty teaching-load limits, in two layers on top of the existing single
 * college threshold (stakeholder Doc 14, ADR 0033).
 *
 * `faculty_load_limits`: one maximum per term, college, and employment type
 * (full-time or part-time). No number is invented for any of them; until an
 * office sets one, that type simply has no limit.
 *
 * `faculty_load_overrides`: a maximum for one professor in one term, set by
 * the Program Head or the Dean with a reason, for when no other professor is
 * available and a load has to go above the normal limit. It outranks every
 * other limit.
 *
 * `dateTime` columns on purpose: MariaDB's legacy `explicit_defaults_for_timestamp
 * = 0` would give a TIMESTAMP column an implicit `ON UPDATE CURRENT_TIMESTAMP`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_load_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->string('college');
            $table->string('employment_type', 20);
            $table->decimal('max_units', 6, 2);
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['academic_term_id', 'college', 'employment_type'], 'faculty_load_limit_term_college_type_unique');
        });

        Schema::create('faculty_load_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('academic_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professor_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('max_units', 6, 2);
            $table->text('reason');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['academic_term_id', 'professor_id'], 'faculty_load_override_term_professor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_load_overrides');
        Schema::dropIfExists('faculty_load_limits');
    }
};

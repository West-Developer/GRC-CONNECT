<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.3 transferee credits.
 *
 * PRD §10.3 describes the source-institution and mapped-grade columns as
 * "approved by GRC" without listing them, so this table stores the minimum
 * needed to represent a credited external subject. `subject_id` is nullable
 * because a transferred subject may not map onto any local subject.
 *
 * No equivalence or passing rule is encoded: PRD §17 leaves the passing-grade
 * rule and equivalent grades open, so `source_grade` is a free string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferee_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->string('source_institution');
            $table->string('source_subject_code');
            $table->string('source_subject_title');
            $table->string('source_grade')->nullable();
            $table->unsignedTinyInteger('credited_units');
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferee_credits');
    }
};

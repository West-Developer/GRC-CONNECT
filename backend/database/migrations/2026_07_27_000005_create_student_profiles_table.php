<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.1 student profile.
 *
 * The PRD also lists "approved contact fields" on this table. They are
 * deliberately NOT created here: which contact details GRC authorizes the
 * system to store is unconfirmed, and inventing them would put unapproved
 * personal-data columns into the schema. They arrive with the approved
 * student-provisioning slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('student_number');
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->unsignedTinyInteger('year_level');
            $table->string('admission_status');
            $table->string('academic_standing');
            $table->timestamps();

            $table->unique('user_id');
            $table->unique('student_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Transferee or Returnee who has nothing to credit says so ("no subject to credit"); the Program Chair
 * reviews it, and once they confirm it the student may go on to enroll (ADR 0026 / 0050, amended).
 *
 * One row per student: declaring twice is the same row. `confirmed_at` null means it is still waiting for
 * the Program Chair. The row goes with the student profile, and the confirming user is kept as a plain
 * reference that survives that user's removal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_no_credit_declarations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained('student_profiles')->cascadeOnDelete();
            $table->timestamp('declared_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('confirmed_at', 'no_credit_declarations_confirmed_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_no_credit_declarations');
    }
};

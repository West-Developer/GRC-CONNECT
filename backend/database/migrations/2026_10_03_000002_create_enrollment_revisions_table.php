<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One round of "the Program Chair changed this student's subjects" (ADR 0040).
 *
 * When a Program Chair adds or removes subjects on an irregular student's
 * submission, the enrollment goes back to the student (`pending_student_review`)
 * with the Chair's note saying why. The student accepts (it goes straight to
 * the Registrar) or declines with a reason (it returns to the Chair). Each trip
 * is a row here, so a student sees exactly what changed and why, and the Chair
 * sees the reason a proposal was declined, across more than one round.
 *
 * `added_subjects` / `removed_subjects` are snapshots (section id, subject code
 * and title, section code, units) taken when the Chair saved, so the history
 * still reads correctly if a section is later renamed or removed.
 * `enrollments.status` is a plain string, so the new status value needs no
 * schema change. `dateTime`, not `timestamp`, for `responded_at` on purpose
 * (MariaDB's legacy `explicit_defaults_for_timestamp = 0` would give a
 * TIMESTAMP an implicit `ON UPDATE CURRENT_TIMESTAMP`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->json('added_subjects');
            $table->json('removed_subjects');
            $table->decimal('units_before', 5, 1);
            $table->decimal('units_after', 5, 1);
            $table->string('status', 20)->default('pending');
            $table->text('student_reason')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'created_at'], 'enrollment_revisions_enrollment_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_revisions');
    }
};

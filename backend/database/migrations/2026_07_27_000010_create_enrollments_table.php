<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.3 enrollments, following the §4.2 lifecycle
 * draft → pending_registrar_approval → pending_payment → enrolled
 * with rejected / cancelled / withdrawn as terminal states.
 *
 * PRD §10.3 requires a "unique active enrollment constraint per student and
 * term". A plain UNIQUE(student_id, academic_term_id) would be wrong: it would
 * permanently bar a student from re-enrolling after a cancellation or
 * withdrawal. Instead `active_academic_term_id` is a STORED generated column
 * that mirrors `academic_term_id` while the row is active and becomes NULL on
 * any terminal state. Because SQL unique indexes ignore NULLs, a student may
 * accumulate any number of terminal enrollments for a term but only one live
 * one — which is exactly the stated rule, enforced by the database rather than
 * by application convention.
 *
 * The terminal values are written as SQL literals rather than imported from
 * App\Domain\Enrollment\EnrollmentStatus on purpose: a migration must stay an
 * immutable snapshot, and importing the enum would let a future edit silently
 * rewrite what this migration means. EnrollmentStatus's docblock carries the
 * matching warning that changing it requires a new migration here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->unsignedSmallInteger('total_units')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('registrar_decided_at')->nullable();
            $table->timestamp('payment_confirmed_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->unsignedBigInteger('active_academic_term_id')
                ->nullable()
                ->storedAs(
                    "case when `status` in ('rejected', 'cancelled', 'withdrawn') "
                    .'then null else `academic_term_id` end'
                );

            $table->unique(
                ['student_id', 'active_academic_term_id'],
                'enrollments_unique_active_per_student_term',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};

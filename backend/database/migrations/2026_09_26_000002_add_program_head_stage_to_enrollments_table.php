<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registrar approval returns for every enrollment (stakeholder Doc 14, ADR
 * 0030), and an irregular or overload enrollment now passes the Program Head
 * first: `pending_program_head_approval` → `pending_registrar_approval` →
 * `pending_payment`. `enrollments.status` is a plain string, so the new value
 * needs no schema change; only the moment the Program Head decided is stored.
 *
 * `dateTime`, not `timestamp`, on purpose: under MariaDB's legacy
 * `explicit_defaults_for_timestamp = 0` a TIMESTAMP column can silently gain
 * `ON UPDATE CURRENT_TIMESTAMP` (see `enrollment_documents.generated_at`).
 *
 * Enrollments that were already waiting for a decision when this ships were
 * waiting for the Program Head when they are irregular or flagged for overload
 * (that was the Program Chair's queue), so they are moved back to that stage
 * and keep showing up where their approver looks. Regular ones stay put and
 * simply join the Registrar's queue. `down()` returns them to the single
 * pre-change stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dateTime('program_head_decided_at')->nullable()->after('registrar_decided_at');
        });

        DB::table('enrollments')
            ->join('student_profiles', 'student_profiles.id', '=', 'enrollments.student_id')
            ->where('enrollments.status', 'pending_registrar_approval')
            ->where(function ($query): void {
                $query->where('enrollments.requires_overload_approval', true)
                    ->orWhere('student_profiles.enrollment_category', 'irregular');
            })
            ->update(['enrollments.status' => 'pending_program_head_approval']);
    }

    public function down(): void
    {
        DB::table('enrollments')
            ->where('status', 'pending_program_head_approval')
            ->update(['status' => 'pending_registrar_approval']);

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('program_head_decided_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The moment a newly created Transferee or Returnee account is deactivated unless the Registrar has
 * approved their enrollment by then (ADR 0048): 7 days after Admission created the account, or
 * after Admission admitted them again. Set only for those two student types; cleared when the
 * Registrar approves an enrollment. NULL for everyone else, and for every account that existed
 * before this column, so none of them is restricted or deactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            // `dateTime`, not `timestamp`, like `freshman_payment_confirmed_at`: MariaDB adds no implicit
            // `ON UPDATE CURRENT_TIMESTAMP` and the stored value does not shift with the session time zone.
            $table->dateTime('enrollment_deadline_at')->nullable();
            $table->index('enrollment_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropIndex(['enrollment_deadline_at']);
            $table->dropColumn('enrollment_deadline_at');
        });
    }
};

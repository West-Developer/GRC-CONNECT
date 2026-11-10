<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full-payment discount percentage quoted to a pay-first student (freshman or
 * transferee) when the Cashier took a full payment (ADR 0046). The money is held as credit
 * before any assessment exists, so the quoted percentage is frozen here and applied when the
 * Registrar approves the enrollment; it is cleared once that settlement has used it.
 * NULL for everyone else and for an installment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->decimal('full_payment_discount_percentage', 4, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('full_payment_discount_percentage');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set once, when the Cashier confirms a freshman's first payment. It is the
 * idempotency key for that confirmation (a repeat finds it set and does
 * nothing) and the gate on the account-setup email: a freshman's setup invitation
 * is sent only after this is set. NULL for every other student. `dateTime`, not
 * `timestamp`, so MariaDB adds no implicit ON UPDATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dateTime('freshman_payment_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('freshman_payment_confirmed_at');
        });
    }
};

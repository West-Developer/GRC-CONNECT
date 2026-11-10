<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate payments against an existing Student balance. `payments` remains
 * the idempotent one-per-enrollment confirmation row that finalizes a current
 * enrollment and generates its COM; this ledger records later balance-only
 * receipts and the enrollment allocation selected by the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['student_id', 'received_at']);
            $table->index('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_payments');
    }
};

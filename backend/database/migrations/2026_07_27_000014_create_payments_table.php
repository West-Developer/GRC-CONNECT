<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.3 payment confirmations.
 *
 * This records that an externally received payment was confirmed by Accounting
 * (PRD §5.3 FR-FIN-007) — it is NOT a payment gateway integration and stores
 * no cardholder or bank data.
 *
 * The UNIQUE constraint on `enrollment_id` is what makes FR-FIN-009's
 * idempotency requirement enforceable: confirming twice cannot create a second
 * payment row. `amount` is nullable because PRD §10.3 marks it "nullable unless
 * approved as required" and §17 leaves the payment confirmation fields open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->string('external_reference')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

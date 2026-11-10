<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single row that says how much the full-payment discount is and when it is valid
 * (ADR 0046). `valid_from` and `valid_until` are school-calendar dates (Asia/Manila), both
 * inclusive; NULL means open-ended. The row is created on first read by
 * `PaymentDiscountSetting::current()` rather than here, so a restored older dump cannot
 * leave payment confirmation without a setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_discount_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('percentage', 4, 1);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_discount_settings');
    }
};

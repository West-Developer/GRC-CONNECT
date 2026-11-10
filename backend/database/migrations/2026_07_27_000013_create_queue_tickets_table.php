<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.3 payment-queue tickets.
 *
 * Both unique constraints come straight from the PRD: one ticket per
 * enrollment, and globally unique ticket numbers. Note that the *numbering
 * scheme*, daily reset cadence, priority rules, and active-serving-number
 * policy are open §17 decisions, so `ticket_number` is an opaque string here
 * and nothing in this slice generates or resets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_number');
            $table->date('queue_date');
            $table->string('status');
            $table->timestamp('served_at')->nullable();
            $table->timestamps();

            $table->unique('enrollment_id');
            $table->unique('ticket_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_tickets');
    }
};

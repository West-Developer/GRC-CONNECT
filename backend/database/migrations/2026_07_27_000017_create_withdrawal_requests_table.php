<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD §10.3 withdrawal and drop requests.
 *
 * `reason` is NOT NULL because PRD §4.2 rule 7 requires a reason for
 * withdrawal — that one is a stated rule rather than an open §17 decision, so
 * it is safe to enforce in the schema. The seat-release consequences of an
 * approved withdrawal remain open under §17 and are not encoded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('status');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};

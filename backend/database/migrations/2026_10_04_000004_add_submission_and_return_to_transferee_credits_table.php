<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit mapping, amended (ADR 0026): a Student now saves their subjects as a
 * private `draft` list and sends it to the Program Chair in one step
 * (`submitted_at`), and the Registrar Head may return an endorsed credit to the
 * Program Chair with a reason (`returned_by`, `returned_at`, `return_reason`).
 * `status` is a plain string, so the new `draft` value needs no change to it.
 * Every column is nullable and additive: existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transferee_credits', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('endorsed_at');
            $table->foreignId('returned_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->text('return_reason')->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        Schema::table('transferee_credits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['return_reason', 'returned_at', 'submitted_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one-time account setup code used to be Laravel's password-broker token:
 * a long random string, verifiable an unlimited number of times until it
 * expired. Stakeholder Doc 14 asks for a six-digit code, which has only a
 * million values, so the code now lives in its own table with the two
 * controls that make a short code safe: it expires, and it dies after a small
 * number of wrong guesses. Only a hash of the code is stored, one row per
 * user (issuing a new code replaces the old one), and the row is deleted when
 * the account is activated.
 *
 * `dateTime` rather than `timestamp` on purpose: under MariaDB's legacy
 * `explicit_defaults_for_timestamp = 0`, the first TIMESTAMP column silently
 * gains `ON UPDATE CURRENT_TIMESTAMP`, which would rewrite `created_at` on
 * every attempt-counter update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_setup_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_setup_codes');
    }
};

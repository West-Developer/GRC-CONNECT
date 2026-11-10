<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second-factor challenge issued at login when `LoginOtpPolicy` decides
 * an account needs a fresh email OTP (auth-hardening batch, 2026-09-29).
 *
 * This table IS a hashed one-time code row, so — unlike the plain
 * `users.last_otp_verified_at` timestamp column added just before this
 * migration — it follows `account_setup_codes`/`password_reset_codes`'s own
 * `dateTime` (not `timestamp`) convention: under MariaDB's legacy
 * `explicit_defaults_for_timestamp = 0`, the first TIMESTAMP column silently
 * gains `ON UPDATE CURRENT_TIMESTAMP`, which would rewrite `created_at` on
 * every attempt-counter update.
 *
 * `token_hash` is the SHA-256 hash of the opaque 32-byte challenge token the
 * frontend carries between "login accepted, OTP required" and "OTP verified"
 * — a fast lookup key, not a low-entropy secret, so it does not need bcrypt's
 * slow comparison (mirrors how Sanctum hashes its own tokens). `code_hash` is
 * still bcrypt, like every other six-digit code in this codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token_hash')->unique();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_otp_challenges');
    }
};

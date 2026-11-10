<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the last time a login email-OTP challenge was verified for this
 * account (auth-hardening batch, 2026-09-29) — `LoginOtpPolicy` reads it to
 * decide whether a fresh OTP is still required (see `auth.login_otp.grace_minutes`).
 * A plain, occasionally-updated column, not a hashed one-time code, so it
 * follows `last_login_at`'s own `timestamp` type rather than the `dateTime`
 * convention used by `account_setup_codes`/`password_reset_codes`/
 * `login_otp_challenges`.
 *
 * Every existing user's very next login after this ships will require one
 * OTP, since this column starts `null` for everyone — expected, one-time,
 * not a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_otp_verified_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_otp_verified_at');
        });
    }
};

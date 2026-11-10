<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A self-service "forgot password" code for an already-active account —
 * a sibling of `account_setup_codes` (auth-hardening batch, 2026-09-29), same
 * six-digit-plus-guess-limit shape, kept in its own table with its own config
 * (`auth.password_reset`) so tuning one never silently retunes the other.
 *
 * The pre-existing `password_reset_tokens` table (Laravel's stock,
 * never-used password-broker scaffolding) is left untouched — removing it is
 * out of scope here.
 *
 * `dateTime` rather than `timestamp` on purpose, for the same reason as
 * `account_setup_codes`: under MariaDB's legacy
 * `explicit_defaults_for_timestamp = 0`, the first TIMESTAMP column silently
 * gains `ON UPDATE CURRENT_TIMESTAMP`, which would rewrite `created_at` on
 * every attempt-counter update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_codes', function (Blueprint $table) {
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
        Schema::dropIfExists('password_reset_codes');
    }
};

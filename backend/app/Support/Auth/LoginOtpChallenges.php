<?php

namespace App\Support\Auth;

use App\Models\LoginOtpChallenge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and checks the login-time email-OTP challenge (auth-hardening
 * batch, 2026-09-29) — the same six-digit/hash/expiry/guess-limit shape as
 * `AccountSetupCodes`/`PasswordResetCodes`, plus an opaque `token` that lets
 * the still-anonymous frontend refer back to "which challenge" across the
 * two-step login without ever exposing the user's identity in the URL or a
 * client-guessable value. The token is hashed with SHA-256 (a fast lookup
 * key, mirroring how Sanctum hashes its own tokens) rather than bcrypt —
 * unlike the low-entropy six-digit code, its 32 bytes of entropy make a slow
 * hash unnecessary.
 *
 * Checking is split from consuming so that a wrong guess is committed on its
 * own even when the caller then aborts its verification transaction.
 */
final class LoginOtpChallenges
{
    /**
     * Replaces any earlier challenge for the user and returns the new plain
     * token and code — the only time either exists in clear text.
     *
     * @return array{token: string, code: string, expiresAt: CarbonImmutable}
     */
    public function issue(User $user): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        DB::transaction(function () use ($user, $code, $token, $expiresAt): void {
            LoginOtpChallenge::query()->where('user_id', $user->id)->delete();

            LoginOtpChallenge::query()->create([
                'user_id' => $user->id,
                'token_hash' => $this->hashToken($token),
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);
        });

        return ['token' => $token, 'code' => $code, 'expiresAt' => $expiresAt];
    }

    public function findByToken(string $token): ?LoginOtpChallenge
    {
        return LoginOtpChallenge::query()->where('token_hash', $this->hashToken($token))->first();
    }

    /**
     * @return array{status: 'ok'|'invalid_code'|'expired_or_missing', user: ?User}
     */
    public function attempt(string $token, string $code): array
    {
        return DB::transaction(function () use ($token, $code): array {
            $row = LoginOtpChallenge::query()
                ->where('token_hash', $this->hashToken($token))
                ->lockForUpdate()
                ->first();

            if (
                ! $row instanceof LoginOtpChallenge
                || $row->expires_at->isPast()
                || $row->attempts >= $this->maxAttempts()
            ) {
                return ['status' => 'expired_or_missing', 'user' => null];
            }

            if (Hash::check($code, $row->code_hash)) {
                return ['status' => 'ok', 'user' => $row->user];
            }

            $row->increment('attempts');

            // The user is still returned on a wrong guess — the caller
            // (VerifyLoginOtp) needs a known actor to audit LOGIN_OTP_FAILED.
            return ['status' => 'invalid_code', 'user' => $row->user];
        });
    }

    /**
     * Uses the challenge up. False when it was already gone (a concurrent
     * verification won), which the caller must treat as an invalid challenge.
     */
    public function consume(User $user): bool
    {
        return LoginOtpChallenge::query()->where('user_id', $user->id)->delete() === 1;
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('auth.login_otp.challenge_ttl_minutes', 10));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('auth.login_otp.max_attempts', 5));
    }
}

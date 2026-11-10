<?php

namespace App\Support\Auth;

use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and checks the six-digit one-time code that lets an already-active
 * account self-service a forgotten password (auth-hardening batch,
 * 2026-09-29). Structurally identical to `AccountSetupCodes` — same
 * six-digit/hash/expiry/guess-limit shape — but a separate table and config
 * (`auth.password_reset`), since this is a different life-cycle moment (an
 * active account resetting its own password, not a pending one finishing
 * setup) and the two limits should be tunable independently.
 *
 * Checking is split from consuming so that a wrong guess is committed on its
 * own even when the caller then aborts its reset transaction.
 */
final class PasswordResetCodes
{
    /**
     * Replaces any earlier code for the user and returns the new plain code,
     * the only time it exists in clear text.
     */
    public function issue(User $user): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $code): void {
            PasswordResetCode::query()->where('user_id', $user->id)->delete();

            PasswordResetCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                'created_at' => now(),
            ]);
        });

        return $code;
    }

    /**
     * True when the code is the user's current, unexpired, not-exhausted code.
     * A wrong guess counts against the code even if the caller rolls back.
     */
    public function attempt(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $row = PasswordResetCode::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $row instanceof PasswordResetCode
                || $row->expires_at->isPast()
                || $row->attempts >= $this->maxAttempts()
            ) {
                return false;
            }

            if (Hash::check($code, $row->code_hash)) {
                return true;
            }

            $row->increment('attempts');

            return false;
        });
    }

    /**
     * Uses the code up. False when it was already gone (a concurrent reset
     * won), which the caller must treat as an invalid code.
     */
    public function consume(User $user): bool
    {
        return PasswordResetCode::query()->where('user_id', $user->id)->delete() === 1;
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('auth.password_reset.expire', 60));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('auth.password_reset.max_attempts', 5));
    }
}

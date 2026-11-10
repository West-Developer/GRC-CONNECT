<?php

namespace App\Support\Auth;

use App\Models\AccountSetupCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Issues and checks the six-digit one-time code that lets an invited Student,
 * Faculty member or staff member set their own password.
 *
 * A six-digit code has one million values, so on its own it would be guessable.
 * It is only acceptable because of the two limits enforced here: the code
 * expires (`auth.passwords.users.expire` minutes, as before) and it stops
 * working after `auth.setup_codes.max_attempts` wrong guesses, after which a
 * new invitation has to be sent. The stored value is a hash, but a hash of a
 * six-digit number is not a secret against someone who can read the table;
 * the limits, not the hash, are the protection.
 *
 * Checking is split from consuming so that a wrong guess is committed on its
 * own even when the caller then aborts its activation transaction.
 */
final class AccountSetupCodes
{
    /**
     * Replaces any earlier code for the user and returns the new plain code,
     * the only time it exists in clear text.
     */
    public function issue(User $user): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $code): void {
            AccountSetupCode::query()->where('user_id', $user->id)->delete();

            AccountSetupCode::query()->create([
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
            $row = AccountSetupCode::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $row instanceof AccountSetupCode
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
     * Uses the code up. False when it was already gone (a concurrent
     * activation won), which the caller must treat as an invalid code.
     */
    public function consume(User $user): bool
    {
        return AccountSetupCode::query()->where('user_id', $user->id)->delete() === 1;
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('auth.passwords.users.expire', 1440));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('auth.setup_codes.max_attempts', 5));
    }
}

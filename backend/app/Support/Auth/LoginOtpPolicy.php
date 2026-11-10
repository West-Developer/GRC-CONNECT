<?php

namespace App\Support\Auth;

use App\Domain\Identity\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Decides whether a login must be challenged with a fresh email OTP
 * (auth-hardening batch, 2026-09-29, owner decision D4). Not required for
 * `queue_kiosk` — a shared physical-kiosk credential, not a personal inbox —
 * and not required again within `grace_minutes` of the account's last
 * verified OTP, so a legitimate user is not asked for a fresh code on every
 * single login. Per-account, not per-device: an explicit, accepted
 * simplification (no device fingerprinting exists or was asked for).
 *
 * Also not required when a Student logs in from behind an already-verified
 * Queue Kiosk device (`$viaVerifiedKioskDevice`) — the kiosk's own
 * claim-ticket flow has a student type their own credentials directly into a
 * shared physical kiosk to identify themselves, where there is no realistic
 * way to check personal email. `LoginController` sets this only after
 * confirming, the same way `EnsureStudentQueueClaimUsesKiosk` already does
 * for ticket claims, that the request carries a live, ability-scoped
 * `X-Queue-Kiosk-Token` — physical possession of that token is itself a
 * strong contextual signal, not a client-trusted flag.
 *
 * Also not required for any account created before `auth.login_otp.
 * enforced_after` — an owner-confirmed transitional gap mirroring the one
 * already applied to the password-complexity rule: existing accounts are not
 * retroactively subjected to the new security, only accounts created from
 * that point forward are. Temporary by design; see PROGRESS.md.
 */
final class LoginOtpPolicy
{
    public function isRequired(User $user, bool $viaVerifiedKioskDevice = false): bool
    {
        if ($user->role === UserRole::QueueKiosk || $viaVerifiedKioskDevice) {
            return false;
        }

        if ($this->isGrandfatheredIn($user)) {
            return false;
        }

        $lastVerified = $user->last_otp_verified_at;

        if (! $lastVerified instanceof CarbonImmutable) {
            return true;
        }

        return $lastVerified->lt(CarbonImmutable::now()->subMinutes($this->graceMinutes()));
    }

    private function graceMinutes(): int
    {
        return max(0, (int) config('auth.login_otp.grace_minutes', 30));
    }

    private function isGrandfatheredIn(User $user): bool
    {
        $cutoff = config('auth.login_otp.enforced_after');

        if (! is_string($cutoff) || trim($cutoff) === '' || $user->created_at === null) {
            return false;
        }

        // `created_at` is a plain (mutable) Carbon\Carbon, not the
        // CarbonImmutable this class casts its own OTP-specific columns to —
        // Eloquent's own timestamp columns are never cast to the immutable
        // variant in this codebase. `lt()` works identically either way.
        return $user->created_at->lt(CarbonImmutable::parse($cutoff));
    }
}

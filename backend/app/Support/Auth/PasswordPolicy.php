<?php

namespace App\Support\Auth;

use Illuminate\Validation\Rules\Password;

/**
 * The single source of truth for how strong a NEWLY set password must be —
 * account setup and the forgot-password reset flow both call this, so
 * tightening the rule never means hunting down more than one place.
 *
 * Deliberately forward-only: this governs a password at the moment it is
 * set, never a password already stored. No existing account is retroactively
 * checked against this rule or forced to change — see the "Known
 * transitional gap" note in the auth-hardening plan for why, and revisit
 * before real production go-live.
 */
final class PasswordPolicy
{
    /**
     * @return array<int, Password|string>
     */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            Password::min(8)->mixedCase()->numbers()->symbols(),
        ];
    }
}

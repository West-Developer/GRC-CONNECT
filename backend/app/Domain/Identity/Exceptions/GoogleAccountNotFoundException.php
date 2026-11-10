<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * Raised when a verified Google email matches no active, non-kiosk GRC
 * account (D1: Google sign-in never auto-creates one). A Google-matched but
 * Disabled account raises this same exception — deliberately indistinguishable
 * from a true non-match, for the same enumeration-safety reason password
 * login already treats "wrong password" and "disabled account" identically.
 */
final class GoogleAccountNotFoundException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'No GRC account was found for this Google email. Please contact Admission or the Registrar\'s Office to have your account created.',
        );
    }
}

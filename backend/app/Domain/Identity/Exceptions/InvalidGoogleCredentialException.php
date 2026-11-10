<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * Raised when a Google ID token fails verification — bad signature, expired,
 * wrong audience/issuer, or an unverified email. The renderer maps this to a
 * generic 401; the caller never learns which specific check failed.
 */
final class InvalidGoogleCredentialException extends RuntimeException
{
    public static function make(): self
    {
        return new self('The Google credential could not be verified.');
    }
}

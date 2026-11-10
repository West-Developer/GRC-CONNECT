<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * Raised for every login failure — unknown email, wrong password, or a
 * disabled account — so the API cannot be used to enumerate accounts or
 * probe account state. The renderer maps this to a single generic 401.
 */
final class InvalidCredentialsException extends RuntimeException
{
    public static function make(): self
    {
        return new self('The provided credentials are incorrect.');
    }
}

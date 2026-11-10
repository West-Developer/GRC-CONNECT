<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * Raised when a login-OTP email fails to send. Unlike the account-setup and
 * password-reset invitation emails (which swallow a delivery failure and
 * still return their generic success response), this one must surface as an
 * error: the user is actively waiting at the still-open sign-in screen for a
 * code that will otherwise never arrive. The renderer maps this to a 503.
 */
final class LoginOtpDeliveryFailedException extends RuntimeException
{
    public static function make(): self
    {
        return new self('The login verification code could not be sent.');
    }
}

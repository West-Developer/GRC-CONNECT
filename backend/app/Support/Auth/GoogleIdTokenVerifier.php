<?php

namespace App\Support\Auth;

use App\Domain\Identity\Exceptions\InvalidGoogleCredentialException;

/**
 * Verifies a Google Identity Services ID token and returns its claims. The
 * only seam the test suite swaps for a fake (bound in
 * `AppServiceProvider::register()`), so no test ever contacts Google or
 * needs a real Google account — only `JwksGoogleIdTokenVerifierTest` touches
 * real JWT/JWKS mechanics, against a locally generated keypair.
 */
interface GoogleIdTokenVerifier
{
    /**
     * @return array{email: string, email_verified: bool, sub: string}
     *
     * @throws InvalidGoogleCredentialException
     */
    public function verify(string $idToken): array;
}

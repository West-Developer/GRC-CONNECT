<?php

namespace Tests\Support;

use App\Domain\Identity\Exceptions\InvalidGoogleCredentialException;
use App\Support\Auth\GoogleIdTokenVerifier;

/**
 * Maps specific fake ID-token strings to specific claim arrays, so
 * `GoogleLoginEndpointTest` never contacts Google or needs a real Google
 * account. Bind in place of the real verifier via
 * `$this->app->instance(GoogleIdTokenVerifier::class, ...)`.
 */
final class FakeGoogleIdTokenVerifier implements GoogleIdTokenVerifier
{
    /**
     * @param  array<string, array{email: string, email_verified: bool, sub: string}>  $claimsByToken
     */
    public function __construct(private readonly array $claimsByToken) {}

    public static function forEmail(string $token, string $email, string $sub = 'google-sub-test'): self
    {
        return new self([
            $token => ['email' => $email, 'email_verified' => true, 'sub' => $sub],
        ]);
    }

    /** @return array{email: string, email_verified: bool, sub: string} */
    public function verify(string $idToken): array
    {
        return $this->claimsByToken[$idToken] ?? throw InvalidGoogleCredentialException::make();
    }
}

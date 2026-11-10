<?php

namespace App\Support\Auth;

use App\Domain\Identity\Exceptions\InvalidGoogleCredentialException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies a real Google Identity Services ID token against Google's
 * published JWKS. `JWT::decode()` validates the signature and `exp`/`nbf`
 * automatically; `aud`, `iss`, and `email_verified` are checked explicitly
 * here, since the library has no opinion on them. No client secret is used
 * or needed anywhere in this flow.
 */
final class JwksGoogleIdTokenVerifier implements GoogleIdTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const CACHE_KEY = 'google-jwks';

    private const CACHE_TTL_MINUTES = 360;

    private const VALID_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function verify(string $idToken): array
    {
        try {
            $keySet = JWK::parseKeySet($this->jwks());
            $claims = (array) JWT::decode($idToken, $keySet);
        } catch (Throwable) {
            throw InvalidGoogleCredentialException::make();
        }

        $audience = (string) config('services.google.client_id');
        $email = $claims['email'] ?? null;
        $subject = $claims['sub'] ?? null;

        if (
            $audience === ''
            || ($claims['aud'] ?? null) !== $audience
            || ! in_array($claims['iss'] ?? null, self::VALID_ISSUERS, true)
            || ($claims['email_verified'] ?? false) !== true
            || ! is_string($email)
            || $email === ''
            || ! is_string($subject)
            || $subject === ''
        ) {
            throw InvalidGoogleCredentialException::make();
        }

        return [
            'email' => $email,
            'email_verified' => true,
            'sub' => $subject,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jwks(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_TTL_MINUTES), function (): array {
            $response = Http::timeout(5)->get(self::JWKS_URL);
            $response->throw();

            return $response->json();
        });
    }
}

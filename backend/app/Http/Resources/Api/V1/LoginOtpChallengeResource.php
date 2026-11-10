<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Returned by `POST /auth/login` and `POST /auth/login/resend-otp` in place
 * of `AuthResource` whenever `LoginOtpPolicy` requires a fresh code — no
 * bearer token exists yet, only an opaque challenge token the frontend
 * carries into `POST /auth/login/verify-otp`.
 *
 * @property-read array{token: string, expiresAt: CarbonImmutable, email: string} $resource
 */
final class LoginOtpChallengeResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     otp_required: bool,
     *     challenge_token: string,
     *     email: string,
     *     expires_at: string,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'login-otp-challenge',
            'otp_required' => true,
            'challenge_token' => $this->resource['token'],
            'email' => $this->resource['email'],
            'expires_at' => $this->resource['expiresAt']->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        // `private` in addition to `no-store` — same reasoning as AuthResource:
        // the challenge token is bearer-equivalent for the login-OTP step.
        $response->headers->set('Cache-Control', 'no-store, private');
    }
}

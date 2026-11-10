<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateUser;
use App\Actions\Auth\IssueSanctumToken;
use App\Actions\Auth\SendLoginOtp;
use App\Domain\Identity\QueueKioskAccess;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\AuthResource;
use App\Http\Resources\Api\V1\LoginOtpChallengeResource;
use App\Models\User;
use App\Support\Audit\AuditRequestContextFactory;
use App\Support\Auth\LoginOtpPolicy;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __invoke(
        LoginRequest $request,
        AuthenticateUser $authenticate,
        IssueSanctumToken $issueToken,
        SendLoginOtp $sendOtp,
        LoginOtpPolicy $otpPolicy,
        AuditRequestContextFactory $contextFactory,
    ): AuthResource|LoginOtpChallengeResource {
        $throttleKey = $request->throttleKey();

        $this->ensureNotRateLimited($throttleKey);

        $context = $contextFactory->fromRequest($request);

        try {
            $user = $authenticate->handle($request->email(), $request->password(), $context);
        } catch (\Throwable $exception) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw $exception;
        }

        RateLimiter::clear($throttleKey);

        if ($otpPolicy->isRequired($user, $this->isVerifiedByKioskDevice($request))) {
            $challenge = $sendOtp->handle($user, $context);

            return LoginOtpChallengeResource::make([
                'token' => $challenge['token'],
                'expiresAt' => $challenge['expiresAt'],
                'email' => $user->email,
            ]);
        }

        $session = $issueToken->handle($user, 'spa-'.$request->ip(), 'password', $context);

        return AuthResource::make($session);
    }

    /**
     * True when this request carries a live, ability-scoped
     * `X-Queue-Kiosk-Token` — the same proof-of-physical-kiosk check
     * `EnsureStudentQueueClaimUsesKiosk` already applies to ticket claims,
     * reused here so a Student typing their own credentials directly into a
     * shared physical kiosk is not asked for an email OTP they have no way
     * to check at that kiosk.
     */
    private function isVerifiedByKioskDevice(LoginRequest $request): bool
    {
        $header = $request->header(QueueKioskAccess::TOKEN_HEADER);
        $kioskToken = is_string($header) ? trim($header) : '';

        if ($kioskToken === '') {
            return false;
        }

        $token = PersonalAccessToken::findToken($kioskToken);

        return $token instanceof PersonalAccessToken
            && ($token->expires_at === null || $token->expires_at->isFuture())
            && $token->tokenable instanceof User
            && $token->tokenable->role === UserRole::QueueKiosk
            && $token->tokenable->status === UserStatus::Active
            && $token->can(QueueKioskAccess::TOKEN_ABILITY);
    }

    private function ensureNotRateLimited(string $throttleKey): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return;
        }

        // The message here never reaches the client — ApiExceptionRenderer
        // substitutes its own fixed, already non-enumerating text for every
        // 429 response app-wide (see its `renderHttpException()`); this
        // exception's own message is only ever visible in server logs.
        throw new TooManyRequestsHttpException(
            RateLimiter::availableIn($throttleKey),
            'Too many login attempts.',
        );
    }
}

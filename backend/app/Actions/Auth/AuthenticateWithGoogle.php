<?php

namespace App\Actions\Auth;

use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\Exceptions\GoogleAccountNotFoundException;
use App\Domain\Identity\Exceptions\InvalidGoogleCredentialException;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Auth\GoogleIdTokenVerifier;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\NewAccessToken;

/**
 * Google Sign-In (auth-hardening batch, 2026-09-29, owner decision D1):
 * verifies the ID token, then matches its verified email to an existing,
 * Active, non-kiosk account. **Never creates a `User` row** — an unmatched
 * or Disabled account raises the identical `GoogleAccountNotFoundException`
 * (enumeration-safe, same reasoning as password login). A successful match
 * also stamps `last_otp_verified_at`: Google's own authentication of that
 * inbox is a reasonable equivalent proof, and it extends the same login-OTP
 * grace window (`LoginOtpPolicy`) to a subsequent password login for the
 * same account.
 */
final class AuthenticateWithGoogle
{
    public function __construct(
        private readonly GoogleIdTokenVerifier $verifier,
        private readonly IssueSanctumToken $issueToken,
    ) {}

    /**
     * @return array{user: User, token: NewAccessToken, expiresAt: ?CarbonImmutable}
     *
     * @throws InvalidGoogleCredentialException
     * @throws GoogleAccountNotFoundException
     */
    public function handle(string $idToken, AuditRequestContext $context): array
    {
        $claims = $this->verifier->verify($idToken);

        $user = User::query()
            ->where('email', mb_strtolower($claims['email']))
            ->where('status', UserStatus::Active)
            ->whereNot('role', UserRole::QueueKiosk)
            ->first();

        if (! $user instanceof User) {
            throw GoogleAccountNotFoundException::make();
        }

        $user->forceFill(['last_otp_verified_at' => CarbonImmutable::now()])->save();

        return $this->issueToken->handle($user, 'spa-google-'.($context->ipAddress ?? 'unknown'), 'google', $context);
    }
}

<?php

namespace App\Actions\Auth;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\LoginOtpChallenges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/**
 * Completes the login-OTP second factor (auth-hardening batch, 2026-09-29):
 * checks the code against the challenge the token identifies, records a
 * wrong guess against the account it belongs to, and — once right — consumes
 * the challenge, stamps `last_otp_verified_at` (starting this account's
 * grace window per `LoginOtpPolicy`), and issues the session exactly like a
 * normal password login would.
 */
final class VerifyLoginOtp
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly LoginOtpChallenges $challenges,
        private readonly IssueSanctumToken $issueToken,
    ) {}

    /**
     * @return array{user: User, token: NewAccessToken, expiresAt: ?CarbonImmutable}
     *
     * @throws ValidationException
     */
    public function handle(string $challengeToken, string $code, AuditRequestContext $context): array
    {
        $result = $this->challenges->attempt($challengeToken, $code);

        if ($result['status'] === 'invalid_code' && $result['user'] instanceof User) {
            $this->auditRecorder->record(
                $result['user'],
                AuditAction::LOGIN_OTP_FAILED,
                AuditableType::USER_ACCOUNT,
                $result['user']->id,
                null,
                null,
                null,
                $context,
            );
        }

        if ($result['status'] !== 'ok' || ! $result['user'] instanceof User) {
            $this->invalidCode();
        }

        $verifiedUser = $result['user'];

        $updated = DB::transaction(function () use ($verifiedUser): User {
            $locked = User::query()->whereKey($verifiedUser->id)->lockForUpdate()->firstOrFail();

            // A wrong guess is committed inside attempt(), so a concurrent
            // consume still counts against the code even though this throws.
            if (! $this->challenges->consume($locked)) {
                $this->invalidCode();
            }

            $locked->forceFill(['last_otp_verified_at' => CarbonImmutable::now()])->save();

            return $locked->refresh();
        });

        return $this->issueToken->handle(
            $updated,
            'spa-otp-'.($context->ipAddress ?? 'unknown'),
            'password_otp',
            $context,
        );
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages([
            'code' => 'This verification code is invalid or expired.',
        ]);
    }
}

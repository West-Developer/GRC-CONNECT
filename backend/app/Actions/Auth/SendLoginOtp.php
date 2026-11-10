<?php

namespace App\Actions\Auth;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\Exceptions\LoginOtpDeliveryFailedException;
use App\Mail\LoginOtpMail;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\LoginOtpChallenges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Issues and emails a fresh login-OTP challenge (auth-hardening batch,
 * 2026-09-29). Unlike `SendPasswordResetCode` and the account-setup
 * invitation actions, a delivery failure here is NOT swallowed: the user is
 * actively waiting at the still-open sign-in screen for a code that would
 * otherwise never arrive, so this throws `LoginOtpDeliveryFailedException`
 * instead of silently reporting and moving on.
 */
final class SendLoginOtp
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly LoginOtpChallenges $challenges,
    ) {}

    /**
     * @return array{token: string, expiresAt: CarbonImmutable}
     *
     * @throws LoginOtpDeliveryFailedException
     */
    public function handle(User $user, AuditRequestContext $context): array
    {
        $issued = $this->challenges->issue($user);

        try {
            Mail::to($user->email)->send(new LoginOtpMail($user->name, $issued['code']));
        } catch (Throwable $exception) {
            report($exception);

            $this->auditRecorder->record(
                $user,
                AuditAction::LOGIN_OTP_CHALLENGE_SEND_FAILED,
                AuditableType::USER_ACCOUNT,
                $user->id,
                null,
                null,
                null,
                $context,
            );

            throw LoginOtpDeliveryFailedException::make();
        }

        $this->auditRecorder->record(
            $user,
            AuditAction::LOGIN_OTP_CHALLENGE_ISSUED,
            AuditableType::USER_ACCOUNT,
            $user->id,
            null,
            null,
            null,
            $context,
        );

        return ['token' => $issued['token'], 'expiresAt' => $issued['expiresAt']];
    }
}

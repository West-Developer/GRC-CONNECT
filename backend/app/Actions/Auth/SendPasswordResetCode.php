<?php

namespace App\Actions\Auth;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\PasswordResetCodes;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails an already-active account a one-time password-reset code
 * (auth-hardening batch, 2026-09-29). Structural clone of
 * `SendStudentAccountSetupInvitation`'s URL-derivation and
 * swallow-and-report-on-mail-failure convention — a reset code arriving a
 * little late isn't blocking anyone mid-flow the way a login OTP is (see
 * `SendLoginOtp`, which deliberately does NOT swallow a delivery failure),
 * so the caller (`ForgotPasswordController`) always returns its one generic
 * response regardless of what happened here.
 */
final class SendPasswordResetCode
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly PasswordResetCodes $resetCodes,
    ) {}

    public function handle(User $user, AuditRequestContext $context): string
    {
        $code = $this->resetCodes->issue($user);
        $resetUrl = $this->resolveResetUrl();

        try {
            Mail::to($user->email)->send(new PasswordResetMail($user->name, $resetUrl, $code, $user->email));

            $this->auditRecorder->record(
                $user,
                AuditAction::PASSWORD_RESET_CODE_SENT,
                AuditableType::USER_ACCOUNT,
                $user->id,
                null,
                ['delivery_status' => 'sent'],
                null,
                $context,
            );

            return 'sent';
        } catch (Throwable $exception) {
            report($exception);

            $this->auditRecorder->record(
                $user,
                AuditAction::PASSWORD_RESET_CODE_SEND_FAILED,
                AuditableType::USER_ACCOUNT,
                $user->id,
                null,
                ['delivery_status' => 'failed'],
                null,
                $context,
            );

            return 'failed';
        }
    }

    private function resolveResetUrl(): string
    {
        $origin = request()?->header('Origin');
        $referer = request()?->header('Referer');
        $baseUrl = null;

        if (is_string($origin) && filter_var($origin, FILTER_VALIDATE_URL)) {
            $baseUrl = $this->originFrom($origin);
        } elseif (is_string($referer) && filter_var($referer, FILTER_VALIDATE_URL)) {
            $baseUrl = $this->originFrom($referer);
        }

        if ($baseUrl === null || ! filter_var($baseUrl, FILTER_VALIDATE_URL) || str_contains($baseUrl, '*')) {
            $configured = (string) config('app.frontend_url', '');
            if (filter_var($configured, FILTER_VALIDATE_URL) && ! str_contains($configured, '*')) {
                $baseUrl = rtrim($configured, '/');
            } else {
                $baseUrl = app()->isProduction()
                    ? 'https://www.grc-enrollment.tech'
                    : 'http://localhost:3000';
            }
        }

        return rtrim($baseUrl, '/').'/reset-password';
    }

    private function originFrom(string $url): ?string
    {
        $parsed = parse_url($url);

        if (! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return $parsed['scheme'].'://'.$parsed['host'].$port;
    }
}

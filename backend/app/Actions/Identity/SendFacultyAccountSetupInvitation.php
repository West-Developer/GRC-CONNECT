<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Mail\FacultyAccountSetupMail;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\AccountSetupCodes;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SendFacultyAccountSetupInvitation
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly AccountSetupCodes $setupCodes,
    ) {}

    public function handle(
        User $faculty,
        User $actor,
        AuditRequestContext $context,
    ): string {
        if (
            $faculty->role !== UserRole::Faculty
            || $faculty->status !== UserStatus::Disabled
            || $faculty->account_setup_completed_at !== null
            || $faculty->archived_at !== null
        ) {
            throw ValidationException::withMessages([
                'faculty' => 'Only a pending faculty account can receive a setup invitation.',
            ]);
        }

        $setupCode = $this->setupCodes->issue($faculty);

        $origin = request()?->header('Origin');
        $referer = request()?->header('Referer');
        $baseUrl = null;
        if (is_string($origin) && filter_var($origin, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($origin);
            if (isset($parsed['scheme'], $parsed['host'])) {
                $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
                $baseUrl = $parsed['scheme'].'://'.$parsed['host'].$port;
            }
        } elseif (is_string($referer) && filter_var($referer, FILTER_VALIDATE_URL)) {
            $parsed = parse_url($referer);
            if (isset($parsed['scheme'], $parsed['host'])) {
                $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
                $baseUrl = $parsed['scheme'].'://'.$parsed['host'].$port;
            }
        }

        if ($baseUrl === null) {
            $baseUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');
        }

        try {
            Mail::to($faculty->email)->send(new FacultyAccountSetupMail(
                rtrim($baseUrl, '/').'/faculty-account-setup',
                $setupCode,
                $faculty->email,
            ));

            $faculty->forceFill([
                'account_setup_invitation_sent_at' => now(),
                'account_setup_invitation_failed_at' => null,
            ])->save();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_ACCOUNT_SETUP_INVITATION_SENT,
                AuditableType::FACULTY_ACCOUNT,
                $faculty->id,
                null,
                ['delivery_status' => 'sent'],
                null,
                $context,
            );

            return 'sent';
        } catch (Throwable $exception) {
            report($exception);

            $faculty->forceFill([
                'account_setup_invitation_failed_at' => now(),
            ])->save();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FACULTY_ACCOUNT_SETUP_INVITATION_FAILED,
                AuditableType::FACULTY_ACCOUNT,
                $faculty->id,
                null,
                ['delivery_status' => 'failed'],
                null,
                $context,
            );

            return 'failed';
        }
    }
}

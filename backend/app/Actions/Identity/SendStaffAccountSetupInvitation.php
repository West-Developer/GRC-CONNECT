<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Mail\StaffAccountSetupMail;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\AccountSetupCodes;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SendStaffAccountSetupInvitation
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly AccountSetupCodes $setupCodes,
    ) {}

    public function handle(
        User $staff,
        User $actor,
        AuditRequestContext $context,
    ): string {
        if (
            ! in_array($staff->role, UserRole::superAdminInvitableCases(), true)
            || $staff->status !== UserStatus::Disabled
            || $staff->account_setup_completed_at !== null
        ) {
            throw ValidationException::withMessages([
                'staff' => 'Only a pending staff account can receive a setup invitation.',
            ]);
        }

        $setupCode = $this->setupCodes->issue($staff);

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
            Mail::to($staff->email)->send(new StaffAccountSetupMail(
                $staff->role,
                rtrim($baseUrl, '/').'/staff-account-setup',
                $setupCode,
                $staff->email,
            ));

            $staff->forceFill([
                'account_setup_invitation_sent_at' => now(),
                'account_setup_invitation_failed_at' => null,
            ])->save();

            $this->auditRecorder->record(
                $actor,
                AuditAction::STAFF_ACCOUNT_SETUP_INVITATION_SENT,
                AuditableType::STAFF_ACCOUNT,
                $staff->id,
                null,
                ['delivery_status' => 'sent', 'role' => $staff->role->value],
                null,
                $context,
            );

            return 'sent';
        } catch (Throwable $exception) {
            report($exception);

            $staff->forceFill([
                'account_setup_invitation_failed_at' => now(),
            ])->save();

            $this->auditRecorder->record(
                $actor,
                AuditAction::STAFF_ACCOUNT_SETUP_INVITATION_FAILED,
                AuditableType::STAFF_ACCOUNT,
                $staff->id,
                null,
                ['delivery_status' => 'failed', 'role' => $staff->role->value],
                null,
                $context,
            );

            return 'failed';
        }
    }
}

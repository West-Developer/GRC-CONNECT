<?php

namespace App\Actions\Identity;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\StudentType;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Mail\StudentAccountSetupMail;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\AccountSetupCodes;
use App\Support\Mail\EnsureExternalMailTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SendStudentAccountSetupInvitation
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly AccountSetupCodes $setupCodes,
        private readonly EnsureExternalMailTransport $mailTransport,
    ) {}

    public function handle(
        StudentProfile $profile,
        User $actor,
        AuditRequestContext $context,
    ): string {
        $student = $profile->user;
        if (
            $student->role !== UserRole::Student
            || $student->status !== UserStatus::Disabled
            || $student->account_setup_completed_at !== null
            || $student->archived_at !== null
        ) {
            throw ValidationException::withMessages([
                'student' => 'Only a pending student account can receive a setup invitation.',
            ]);
        }

        // A freshman gets their account only after the Cashier confirms the first
        // payment; until then Admission sends a queue number instead. Every route
        // to this email (Admission, Super Admin, the public resend) ends here, so
        // the gate lives here.
        if ($profile->isFreshmanAwaitingPayment()) {
            throw ValidationException::withMessages([
                'student' => 'This freshman has not paid yet. The account setup email is sent after the Cashier confirms the first payment.',
            ]);
        }

        $setupCode = $this->setupCodes->issue($student);

        $origin = request()->header('Origin');
        $referer = request()->header('Referer');
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

        // Link straight to the setup page (like the faculty and staff emails) rather than
        // relying on the landing page to redirect `/?code=` — a bare `https://host?x=y` link is
        // not reliably tappable in every mail client.
        $setupUrl = rtrim($baseUrl, '/').'/account-setup';

        try {
            $this->mailTransport->check();
            Mail::to($student->email)->send(new StudentAccountSetupMail(
                $student->name,
                $setupUrl,
                $setupCode,
                $student->email,
            ));

            $student->forceFill([
                'account_setup_invitation_sent_at' => now(),
                'account_setup_invitation_failed_at' => null,
            ])->save();

            // A student whose 7 days ran out is admitted again by Admission, the same step as creating
            // the account: a fresh 7 days. A deadline still in the future is never extended by a resend,
            // and an account without one (it predates the rule) never gets one (ADR 0048).
            $deadlineRestarted = $profile->enrollment_deadline_at !== null
                && $profile->enrollment_deadline_at->lessThanOrEqualTo(now());
            if ($deadlineRestarted) {
                $profile->forceFill([
                    'enrollment_deadline_at' => now()->addDays(StudentType::ENROLLMENT_DEADLINE_DAYS),
                ])->save();
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::STUDENT_ACCOUNT_SETUP_INVITATION_SENT,
                AuditableType::STUDENT_PROFILE,
                $profile->id,
                null,
                [
                    'delivery_status' => 'sent',
                    ...($deadlineRestarted ? ['enrollment_deadline_restarted' => true] : []),
                ],
                null,
                $context,
            );

            return 'sent';
        } catch (Throwable $exception) {
            report($exception);

            $student->forceFill([
                'account_setup_invitation_failed_at' => now(),
            ])->save();

            $this->auditRecorder->record(
                $actor,
                AuditAction::STUDENT_ACCOUNT_SETUP_INVITATION_FAILED,
                AuditableType::STUDENT_PROFILE,
                $profile->id,
                null,
                ['delivery_status' => 'failed'],
                null,
                $context,
            );

            return 'failed';
        }
    }
}

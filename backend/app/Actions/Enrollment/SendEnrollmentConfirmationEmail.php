<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Mail\EnrollmentConfirmedMail;
use App\Models\Enrollment;
use App\Models\EnrollmentDocument;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the student once their enrollment is finalized — called from
 * `EnrollmentController::confirmPayment()`, outside `ConfirmPayment`'s own
 * DB transaction, and only when that call actually created new records
 * (`created: true`), so a repeat/idempotent confirmation never resends it.
 *
 * Follows `SendPasswordResetCode`'s swallow-and-report-on-failure
 * convention, not `SendLoginOtp`'s throw-on-failure one: the student is not
 * actively waiting on this email the way a login OTP is, and a delivery
 * hiccup must never make payment confirmation itself appear to fail.
 *
 * No PDF is attached — per `BuildCorSnapshot`'s own "no PDF pipeline" design
 * note, the COR is protected structured data rendered by the portal, so the
 * email links back into it instead.
 */
final class SendEnrollmentConfirmationEmail
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function handle(Enrollment $enrollment, EnrollmentDocument $document, User $actor, AuditRequestContext $context): string
    {
        $recipient = $enrollment->student->user;
        $portalUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/').'/portal/enrollment';

        try {
            Mail::to($recipient->email)->send(new EnrollmentConfirmedMail(
                $recipient->name,
                $document->document_number,
                $document->snapshot ?? [],
                $portalUrl,
            ));

            $this->auditRecorder->record(
                $actor,
                AuditAction::ENROLLMENT_CONFIRMATION_EMAIL_SENT,
                AuditableType::ENROLLMENT,
                $enrollment->id,
                null,
                ['delivery_status' => 'sent'],
                null,
                $context,
            );

            return 'sent';
        } catch (Throwable $exception) {
            report($exception);

            $this->auditRecorder->record(
                $actor,
                AuditAction::ENROLLMENT_CONFIRMATION_EMAIL_SEND_FAILED,
                AuditableType::ENROLLMENT,
                $enrollment->id,
                null,
                ['delivery_status' => 'failed'],
                null,
                $context,
            );

            return 'failed';
        }
    }
}

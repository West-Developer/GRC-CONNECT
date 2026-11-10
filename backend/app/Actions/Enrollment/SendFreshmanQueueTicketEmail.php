<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\StudentType;
use App\Mail\FreshmanQueueTicketMail;
use App\Mail\TransfereeQueueTicketMail;
use App\Models\QueueTicket;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Http\FrontendBaseUrl;
use App\Support\Mail\EnsureExternalMailTransport;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails a freshman their queue number and the "view the live queue" link right
 * after Admission records them. Follows the swallow-and-report-on-failure
 * convention of `SendEnrollmentConfirmationEmail`: Admission is not waiting on
 * this email, a delivery hiccup must never make the intake itself fail, and the
 * outcome is stored (`link_sent_at` / `link_failed_at`) so Admission can see it
 * and send the link again.
 *
 * The link is `<frontend>/queue/live?t=<token>`; the token is the plain value
 * from `IssueFreshmanQueueTicket` / `RotateFreshmanQueueToken` (only its hash is
 * stored). The base URL is the configured one, never a request header.
 */
final class SendFreshmanQueueTicketEmail
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly EnsureExternalMailTransport $mailTransport,
    ) {}

    public function handle(QueueTicket $ticket, string $plainToken, User $actor, AuditRequestContext $context): string
    {
        $profile = $ticket->studentProfile()->with('user')->firstOrFail();
        $student = $profile->user;
        $liveUrl = FrontendBaseUrl::resolve().'/queue/live?t='.$plainToken;

        try {
            $this->mailTransport->check();
            // A transferee already has an account and still has to choose their subjects, so their
            // email says so; a freshman's account is set up only after they pay.
            $mailable = $profile->student_type === StudentType::Transferee
                ? new TransfereeQueueTicketMail($student->name, $ticket->ticket_number, $liveUrl)
                : new FreshmanQueueTicketMail($student->name, $ticket->ticket_number, $liveUrl);

            Mail::to($student->email)->send($mailable);

            $ticket->forceFill(['link_sent_at' => now(), 'link_failed_at' => null])->save();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FRESHMAN_QUEUE_LINK_EMAIL_SENT,
                AuditableType::QUEUE_TICKET,
                $ticket->id,
                null,
                ['delivery_status' => 'sent'],
                null,
                $context,
            );

            return 'sent';
        } catch (Throwable $exception) {
            report($exception);

            $ticket->forceFill(['link_failed_at' => now()])->save();

            $this->auditRecorder->record(
                $actor,
                AuditAction::FRESHMAN_QUEUE_LINK_EMAIL_SEND_FAILED,
                AuditableType::QUEUE_TICKET,
                $ticket->id,
                null,
                ['delivery_status' => 'failed'],
                null,
                $context,
            );

            return 'failed';
        }
    }
}

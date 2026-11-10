<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\EnrollmentStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Notifications\NotificationType;
use App\Models\Enrollment;
use App\Models\QueueTicket;
use App\Models\User;
use App\Support\Notifications\NotificationRecorder;
use Illuminate\Validation\ValidationException;

/**
 * Issues the one queue ticket an approved (`pending_payment`) enrollment
 * may ever hold (`unique(enrollment_id)` on `queue_tickets` makes this
 * idempotent by construction — a repeat claim returns the existing ticket
 * rather than erroring). Replaces the old registrar-approval-time
 * auto-issue (see `TransitionEnrollment`): a student now has no queue
 * number until they claim one, or Accounting Staff issues one on their
 * behalf.
 *
 * The numbering itself (open cycle, carry-over, `Q%03d`) lives in
 * `AllocateQueueTicket`, shared with the freshman path
 * (`IssueFreshmanQueueTicket`). See
 * docs/superpowers/specs/2026-08-23-queue-kiosk-claim-carryover-cutoff-design.md.
 *
 * The claim self-guards the `pending_payment` status (ADR 0011), the same
 * convention `TransitionEnrollment`/`TransitionQueueTicket`/
 * `TransitionQueueCycle` follow — even though today's only caller
 * (`QueueTicketController::resolveEnrollment()`) already filters to
 * `pending_payment` before ever reaching `execute()`, so this Action stays
 * safe against a future caller (a kiosk-claim slice is expected to call
 * `execute()` directly) that might not.
 */
final readonly class ClaimQueueTicket
{
    public function __construct(
        private AllocateQueueTicket $allocator,
        private NotificationRecorder $notificationRecorder,
    ) {}

    public function execute(Enrollment $enrollment, User $actor, AuditRequestContext $context): QueueTicket
    {
        $ticket = $this->allocator->execute(
            ['enrollment_id' => $enrollment->id],
            fn (): ?QueueTicket => QueueTicket::query()->where('enrollment_id', $enrollment->id)->first(),
            $actor,
            $context,
            guard: function () use ($enrollment): void {
                if ($enrollment->status !== EnrollmentStatus::PendingPayment) {
                    throw ValidationException::withMessages([
                        'enrollment' => "This enrollment is not pending payment; it is currently '{$enrollment->status->value}'.",
                    ]);
                }
            },
            afterCreate: function (QueueTicket $ticket) use ($enrollment): void {
                $this->notificationRecorder->recordManyForRole(
                    UserRole::AccountingStaff,
                    NotificationType::QueueTicketClaimed,
                    "{$enrollment->student->student_number} claimed queue ticket {$ticket->ticket_number}.",
                );
            },
        );

        return $ticket->loadMissing(['enrollment.student']);
    }
}

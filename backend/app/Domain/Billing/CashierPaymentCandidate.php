<?php

namespace App\Domain\Billing;

use App\Models\Enrollment;
use App\Models\QueueTicket;
use App\Models\StudentProfile;

/**
 * The exact existing record a Cashier may bring into the payment workflow.
 * The lookup never creates, serves, skips, or confirms anything; it merely
 * returns the loaded records needed for the UI to decide whether the
 * existing queue transition can be offered. `ticket` is null when the
 * student is approved (`pending_payment`) but has not yet claimed a queue
 * ticket — the UI offers "Issue queue ticket" instead of "Serve" in that
 * case (see `App\Actions\Enrollment\ClaimQueueTicket`).
 */
final readonly class CashierPaymentCandidate
{
    public function __construct(
        public StudentProfile $student,
        public Enrollment $enrollment,
        public ?QueueTicket $ticket,
    ) {}
}

<?php

namespace App\Actions\Enrollment;

use App\Domain\Audit\AuditRequestContext;
use App\Domain\Enrollment\QueueLinkOutbox;
use App\Models\User;

/**
 * Emails the queue-number links a request collected in its `QueueLinkOutbox`, after
 * the transaction has committed. A delivery failure is swallowed and recorded by
 * `SendFreshmanQueueTicketEmail`, so it can never undo the ticket or the decision
 * that issued it; a ticket that was not newly issued has no token and is skipped.
 */
final readonly class SendQueueLinks
{
    public function __construct(private SendFreshmanQueueTicketEmail $sendEmail) {}

    public function handle(QueueLinkOutbox $outbox, User $actor, AuditRequestContext $context): void
    {
        foreach ($outbox->drain() as $issued) {
            if ($issued->plainToken !== null) {
                $this->sendEmail->handle($issued->ticket, $issued->plainToken, $actor, $context);
            }
        }
    }
}

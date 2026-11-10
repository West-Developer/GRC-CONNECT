<?php

namespace App\Domain\Enrollment;

use App\Models\QueueTicket;

/**
 * The result of issuing a freshman's queue ticket. `plainToken` is the secret
 * in the emailed "view my queue number" link; it exists only here, on the call
 * that created the ticket (only its hash is stored), and is `null` when the
 * ticket already existed.
 */
final readonly class IssuedFreshmanQueueTicket
{
    public function __construct(
        public QueueTicket $ticket,
        public ?string $plainToken,
    ) {}
}

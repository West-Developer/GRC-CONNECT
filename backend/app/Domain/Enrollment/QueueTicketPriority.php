<?php

namespace App\Domain\Enrollment;

/**
 * PROVISIONAL — PRD §17 leaves the queue's priority policy unconfirmed (see
 * `QueueTicketStatus`'s identical disclaimer). Every ticket starts
 * `Regular`; only Accounting Staff marking a ticket `Priority` after
 * issuance (an audited action — see `TransitionQueueTicket`) changes this.
 * No eligibility rule (PWD, senior citizen, etc.) is encoded anywhere —
 * the cashier's own judgment is the entire mechanism.
 */
enum QueueTicketPriority: string
{
    case Regular = 'regular';
    case Priority = 'priority';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Priority => 'Priority',
        };
    }
}

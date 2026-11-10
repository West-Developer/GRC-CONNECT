<?php

namespace App\Domain\Enrollment;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * PRD §17 explicitly leaves "queue-ticket reset, priority, active serving-number
 * policy, and the exact Accounting Staff authority for recording Payment
 * Confirmation" open pending GRC approval. These values let the schema and
 * seeders exist without asserting a queue policy; no reset cadence, priority
 * rule, or numbering scheme is encoded anywhere in this slice.
 */
enum QueueTicketStatus: string
{
    case Waiting = 'waiting';
    case Serving = 'serving';
    case Served = 'served';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Serving => 'Serving',
            self::Served => 'Served',
            self::Cancelled => 'Cancelled',
        };
    }
}

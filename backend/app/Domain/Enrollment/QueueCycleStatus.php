<?php

namespace App\Domain\Enrollment;

/**
 * A `queue_cycles` row's status is fully derived from its own columns —
 * this enum exists only to give the three derived states stable string
 * values and labels for `QueueCycleResource`. Never stored on the row
 * itself; see `QueueCycle::status()`.
 */
enum QueueCycleStatus: string
{
    case Open = 'open';
    case CutOff = 'cut_off';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::CutOff => 'Cut off for today',
            self::Closed => 'Closed',
        };
    }
}

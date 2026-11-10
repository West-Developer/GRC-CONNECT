<?php

namespace App\Domain\Enrollment;

/**
 * The part of the live queue everyone may see: the number being served and the
 * next waiting numbers. Ticket numbers only — never whose they are.
 */
final readonly class QueueBoard
{
    /**
     * @param  list<string>  $upcomingTicketNumbers
     */
    public function __construct(
        public ?string $nowServingTicketNumber,
        public array $upcomingTicketNumbers,
        public bool $cutOffToday,
    ) {}
}

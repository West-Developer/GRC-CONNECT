<?php

namespace App\Domain\Enrollment;

use App\Models\QueueTicket;

/**
 * Read-only aggregate behind the student's own "where am I in the queue"
 * view (PRD §5.3 FR-FIN-006) — see
 * `App\Actions\Enrollment\BuildStudentQueueView`.
 */
final readonly class StudentQueueView
{
    /**
     * @param  list<string>  $upcomingTicketNumbers
     */
    public function __construct(
        public string $stage,
        public bool $canClaim,
        public ?QueueTicket $ticket,
        public ?string $nowServingTicketNumber,
        public array $upcomingTicketNumbers,
        public bool $cutOffToday,
    ) {}
}

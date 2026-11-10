<?php

namespace App\Actions\Enrollment;

use App\Domain\Enrollment\QueueBoard;
use App\Domain\Enrollment\QueueCycleStatus;
use App\Domain\Enrollment\QueueTicketPriority;
use App\Domain\Enrollment\QueueTicketStatus;
use App\Models\QueueCycle;
use App\Models\QueueTicket;

/**
 * The shared live board of the open queue cycle: which number is being served,
 * the next waiting numbers (priority tickets first), and whether today's
 * cut-off has been reached. Used by the signed-in student's own queue view
 * (`BuildStudentQueueView`) and by the freshman's public link
 * (`BuildPublicQueueView`). It exposes ticket numbers only — never the identity
 * behind one (the privacy convention `QueueTicket::position()` already follows).
 */
final readonly class BuildQueueBoard
{
    private const UPCOMING_LIMIT = 10;

    public function execute(): QueueBoard
    {
        $openCycle = QueueCycle::query()->whereNull('closed_at')->first();

        if ($openCycle === null) {
            return new QueueBoard(null, [], false);
        }

        $nowServing = QueueTicket::query()
            ->where('queue_cycle_id', $openCycle->id)
            ->where('status', QueueTicketStatus::Serving)
            ->value('ticket_number');

        return new QueueBoard(
            $nowServing === null ? null : (string) $nowServing,
            $this->upcomingTicketNumbers($openCycle->id),
            $openCycle->status() === QueueCycleStatus::CutOff,
        );
    }

    /**
     * @return list<string>
     */
    private function upcomingTicketNumbers(int $openCycleId): array
    {
        $orderedWaiting = fn (QueueTicketPriority $priority) => QueueTicket::query()
            ->where('queue_cycle_id', $openCycleId)
            ->where('status', QueueTicketStatus::Waiting)
            ->where('priority', $priority)
            ->orderBy('queue_date')
            ->orderByRaw('COALESCE(requeued_at, created_at)')
            ->orderByRaw('requeued_at IS NOT NULL')
            ->orderBy('id');

        $priorityNumbers = $orderedWaiting(QueueTicketPriority::Priority)
            ->limit(self::UPCOMING_LIMIT)
            ->pluck('ticket_number')
            ->all();

        $remaining = self::UPCOMING_LIMIT - count($priorityNumbers);

        $regularNumbers = $remaining > 0
            ? $orderedWaiting(QueueTicketPriority::Regular)->limit($remaining)->pluck('ticket_number')->all()
            : [];

        // array_values(array_map(...)) is not decorative: pluck('ticket_number')
        // ->all() is typed array<mixed>, which static analysis cannot narrow to
        // a list<string> on its own.
        return array_values(array_map(
            static fn (mixed $ticketNumber): string => (string) $ticketNumber,
            [...$priorityNumbers, ...$regularNumbers],
        ));
    }
}

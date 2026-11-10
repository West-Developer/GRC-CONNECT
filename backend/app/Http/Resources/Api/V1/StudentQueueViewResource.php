<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Enrollment\StudentQueueView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read StudentQueueView $resource
 */
final class StudentQueueViewResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     stage: string,
     *     can_claim: bool,
     *     ticket: ?array{ticket_number: string, status: string, status_label: string, priority: string, priority_label: string, position: ?int, announce_count: int},
     *     now_serving_ticket_number: ?string,
     *     upcoming_ticket_numbers: list<string>,
     *     cut_off_today: bool
     * }
     */
    public function toArray(Request $request): array
    {
        $ticket = $this->resource->ticket;

        return [
            'type' => 'student_queue_view',
            'stage' => $this->resource->stage,
            'can_claim' => $this->resource->canClaim,
            'ticket' => $ticket === null ? null : [
                'ticket_number' => $ticket->ticket_number,
                'status' => $ticket->status->value,
                'status_label' => $ticket->status->label(),
                'priority' => $ticket->priority->value,
                'priority_label' => $ticket->priority->label(),
                'position' => $ticket->position(),
                'announce_count' => $ticket->announce_count,
            ],
            'now_serving_ticket_number' => $this->resource->nowServingTicketNumber,
            'upcoming_ticket_numbers' => $this->resource->upcomingTicketNumbers,
            'cut_off_today' => $this->resource->cutOffToday,
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Billing\CashierPaymentCandidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read CashierPaymentCandidate $resource
 */
final class CashierPaymentCandidateResource extends JsonResource
{
    /**
     * @return array{type: string, student_id: int, student_name: string, student_number: string, year_level: int, enrollment_id: int, ticket: ?array{id: int, ticket_number: string, status: string}}
     */
    public function toArray(Request $request): array
    {
        $ticket = $this->resource->ticket;

        return [
            'type' => 'cashier_payment_candidate',
            'student_id' => $this->resource->student->id,
            'student_name' => $this->resource->student->user->name,
            'student_number' => $this->resource->student->student_number,
            'year_level' => $this->resource->student->year_level,
            'enrollment_id' => $this->resource->enrollment->id,
            'ticket' => $ticket === null ? null : [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'status' => $ticket->status->value,
            ],
        ];
    }
}

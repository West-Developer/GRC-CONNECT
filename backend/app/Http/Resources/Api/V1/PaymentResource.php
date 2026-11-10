<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Payment $resource
 */
final class PaymentResource extends JsonResource
{
    /**
     * Exact key set. `student_number` (never email or name) matches
     * `EnrollmentResource`'s precedent. `confirmed_by` is deliberately
     * absent — actor identity is never rendered, the same convention
     * `PaymentConfirmationResource`'s embedded `payment` array already
     * follows.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     enrollment_id: int,
     *     student_number: string,
     *     external_reference: ?string,
     *     amount: ?string,
     *     confirmed_at: string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'payment',
            'id' => $this->resource->id,
            'enrollment_id' => $this->resource->enrollment_id,
            'student_number' => $this->resource->enrollment->student->student_number,
            'external_reference' => $this->resource->external_reference,
            'amount' => $this->resource->amount,
            'confirmed_at' => $this->resource->confirmed_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

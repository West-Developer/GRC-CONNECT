<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read object{id: string, transaction_type: string, student_id: int|string, student_name: string, student_number: string, enrollment_id: int|string, amount: string, processed_at: string} $resource
 */
final class CashierTransactionResource extends JsonResource
{
    /**
     * @return array{type: string, id: string, transaction_type: string, student_id: int, student_name: string, student_number: string, enrollment_id: int, amount: string, processed_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'cashier_transaction',
            'id' => $this->resource->id,
            'transaction_type' => $this->resource->transaction_type,
            'student_id' => (int) $this->resource->student_id,
            'student_name' => $this->resource->student_name,
            'student_number' => $this->resource->student_number,
            'enrollment_id' => $this->resource->enrollment_id !== null ? (int) $this->resource->enrollment_id : null,
            'amount' => $this->resource->amount,
            'processed_at' => CarbonImmutable::parse($this->resource->processed_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

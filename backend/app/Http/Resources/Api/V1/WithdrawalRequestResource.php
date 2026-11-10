<?php

namespace App\Http\Resources\Api\V1;

use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read WithdrawalRequest $resource
 */
final class WithdrawalRequestResource extends JsonResource
{
    /**
     * Exact key set. `student_number` (never email or name) lets Registrar
     * Staff/Head identify whose request this is, matching
     * `EnrollmentResource`'s precedent. The processor's identity stays
     * private to the audit log — never exposed here, matching every other
     * resource in this codebase (e.g. `EnrollmentResource` exposes
     * `registrar_decided_at` but never who decided).
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     enrollment_id: int,
     *     student_number: string,
     *     reason: string,
     *     status: string,
     *     status_label: string,
     *     processed_at: ?string,
     *     created_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'withdrawal_request',
            'id' => $this->resource->id,
            'enrollment_id' => $this->resource->enrollment_id,
            'student_number' => $this->resource->enrollment->student->student_number,
            'reason' => $this->resource->reason,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'processed_at' => $this->resource->processed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->resource->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

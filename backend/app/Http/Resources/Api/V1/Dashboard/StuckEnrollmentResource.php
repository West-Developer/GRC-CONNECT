<?php

namespace App\Http\Resources\Api\V1\Dashboard;

use App\Domain\Dashboard\StuckEnrollmentRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read StuckEnrollmentRow $resource
 */
final class StuckEnrollmentResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     enrollment_id: int,
     *     student_number: string,
     *     status: string,
     *     status_label: string,
     *     days_in_status: int,
     *     is_flagged: bool
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'stuck_enrollment',
            'enrollment_id' => $this->resource->enrollmentId,
            'student_number' => $this->resource->studentNumber,
            'status' => $this->resource->status,
            'status_label' => $this->resource->statusLabel,
            'days_in_status' => $this->resource->daysInStatus,
            'is_flagged' => $this->resource->isFlagged,
        ];
    }
}

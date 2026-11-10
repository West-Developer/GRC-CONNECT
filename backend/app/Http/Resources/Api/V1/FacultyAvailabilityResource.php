<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FacultyAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FacultyAvailability $resource
 */
final class FacultyAvailabilityResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     professor_id: int,
     *     day_of_week: int,
     *     starts_at_time: string,
     *     ends_at_time: string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'faculty_availability',
            'id' => $this->resource->id,
            'professor_id' => $this->resource->professor_id,
            'day_of_week' => $this->resource->day_of_week,
            'starts_at_time' => $this->resource->starts_at_time,
            'ends_at_time' => $this->resource->ends_at_time,
        ];
    }
}

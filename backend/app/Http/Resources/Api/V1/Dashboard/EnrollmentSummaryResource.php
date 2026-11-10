<?php

namespace App\Http\Resources\Api\V1\Dashboard;

use App\Domain\Dashboard\EnrollmentSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EnrollmentSummary $resource
 */
final class EnrollmentSummaryResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     academic_term_id: int,
     *     status_counts: array<string, int>,
     *     funnel_counts: array<string, int>,
     *     total_sections: int,
     *     published_sections: int,
     *     total_capacity: int,
     *     total_enrolled_seats: int,
     *     grade_status_counts: array<string, int>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'enrollment_summary',
            'academic_term_id' => $this->resource->academicTermId,
            'status_counts' => $this->resource->statusCounts,
            'funnel_counts' => $this->resource->funnelCounts,
            'total_sections' => $this->resource->totalSections,
            'published_sections' => $this->resource->publishedSections,
            'total_capacity' => $this->resource->totalCapacity,
            'total_enrolled_seats' => $this->resource->totalEnrolledSeats,
            'grade_status_counts' => $this->resource->gradeStatusCounts,
        ];
    }
}

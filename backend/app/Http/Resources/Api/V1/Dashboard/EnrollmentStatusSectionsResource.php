<?php

namespace App\Http\Resources\Api\V1\Dashboard;

use App\Domain\Dashboard\EnrollmentStatusSectionBreakdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EnrollmentStatusSectionBreakdown $resource
 */
final class EnrollmentStatusSectionsResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     academic_term_id: int,
     *     department: ?string,
     *     sections: list<array{section_code: ?string, total: int, groups: array<string, int>}>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'enrollment_status_sections',
            'academic_term_id' => $this->resource->academicTermId,
            'department' => $this->resource->department,
            'sections' => $this->resource->sections,
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1\Dashboard;

use App\Domain\Dashboard\EnrollmentStatusOverview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EnrollmentStatusOverview $resource
 */
final class EnrollmentStatusOverviewResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     academic_term_id: int,
     *     total_students: int,
     *     groups: array<string, int>,
     *     steps: array<string, int>,
     *     departments: list<array{department: ?string, label: string, total: int, groups: array<string, int>, steps?: array<string, int>}>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'enrollment_status_overview',
            'academic_term_id' => $this->resource->academicTermId,
            'total_students' => $this->resource->totalStudents,
            'groups' => $this->resource->groups,
            'steps' => $this->resource->steps,
            'departments' => $this->resource->departments,
        ];
    }
}

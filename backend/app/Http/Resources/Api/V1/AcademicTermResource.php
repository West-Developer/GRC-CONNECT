<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AcademicTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AcademicTerm $resource
 */
final class AcademicTermResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     school_year: string,
     *     semester: string,
     *     starts_at: ?string,
     *     ends_at: ?string,
     *     enrollment_opens_at: ?string,
     *     enrollment_closes_at: ?string,
     *     add_drop_opens_at: ?string,
     *     add_drop_deadline_at: ?string,
     *     enrollment_platform: ?string,
     *     enrollment_platform_label: ?string,
     *     grading_deadline_at: ?string,
     *     closed_at: ?string,
     *     archived_at: ?string,
     *     status: string,
     *     status_label: string,
     *     is_actionable_current: bool,
     *     next_term_sequence: array{school_year: string, semester: string}
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'academic-term',
            'id' => $this->resource->id,
            'school_year' => $this->resource->school_year,
            'semester' => $this->resource->semester,
            'starts_at' => $this->resource->starts_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $this->resource->ends_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'enrollment_opens_at' => $this->resource->enrollment_opens_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'enrollment_closes_at' => $this->resource->enrollment_closes_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'add_drop_opens_at' => $this->resource->add_drop_opens_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'add_drop_deadline_at' => $this->resource->add_drop_deadline_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'enrollment_platform' => $this->resource->enrollment_platform?->value,
            'enrollment_platform_label' => $this->resource->enrollment_platform?->label(),
            'grading_deadline_at' => $this->resource->grading_deadline_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'closed_at' => $this->resource->closed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'archived_at' => $this->resource->archived_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'is_actionable_current' => $this->resource->isActionableCurrent(),
            'next_term_sequence' => $this->resource->nextSequence(),
        ];
    }
}

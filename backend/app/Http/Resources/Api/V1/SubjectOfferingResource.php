<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SubjectOffering;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Assumes `subject` is eager-loaded by the caller (PRD §10.6 forbids N+1
 * loading).
 *
 * @property-read SubjectOffering $resource
 */
final class SubjectOfferingResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     academic_term_id: int,
     *     curriculum_id: int,
     *     subject_id: int,
     *     subject_code: string,
     *     subject_title: string,
     *     subject_units: float,
     *     year_level: int,
     *     semester: string,
     *     min_section_capacity: int,
     *     max_section_capacity: int,
     *     recommended_sections: int
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'subject-offering',
            'id' => $this->resource->id,
            'academic_term_id' => $this->resource->academic_term_id,
            'curriculum_id' => $this->resource->curriculum_id,
            'subject_id' => $this->resource->subject_id,
            'subject_code' => $this->resource->subject->code,
            'subject_title' => $this->resource->subject->title,
            'subject_units' => $this->resource->subject->units,
            'year_level' => $this->resource->year_level,
            'semester' => $this->resource->semester,
            'min_section_capacity' => $this->resource->min_section_capacity,
            'max_section_capacity' => $this->resource->max_section_capacity,
            'recommended_sections' => $this->resource->recommended_sections,
        ];
    }
}

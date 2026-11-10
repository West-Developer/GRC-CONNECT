<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FacultyAssignmentRecommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read FacultyAssignmentRecommendation $resource */
final class FacultyAssignmentRecommendationResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     id: int,
     *     schedule_generation_run_id: int,
     *     section_id: int,
     *     recommended_professor_id: ?int,
     *     preference_rank: ?int,
     *     specialization_match: ?string,
     *     availability_match: bool,
     *     conflict_free: bool,
     *     rationale: list<string>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'faculty_assignment_recommendation',
            'id' => $this->resource->id,
            'schedule_generation_run_id' => $this->resource->schedule_generation_run_id,
            'section_id' => $this->resource->section_id,
            'recommended_professor_id' => $this->resource->recommended_professor_id,
            'preference_rank' => $this->resource->preference_rank,
            'specialization_match' => $this->resource->specialization_match?->value,
            'availability_match' => $this->resource->availability_match,
            'conflict_free' => $this->resource->conflict_free,
            'rationale' => array_values($this->resource->rationale ?? []),
        ];
    }
}

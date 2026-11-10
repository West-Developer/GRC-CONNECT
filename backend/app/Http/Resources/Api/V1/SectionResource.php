<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Scheduling\CanonicalScheduleDays;
use App\Models\Section;
use App\Support\Http\ProfessorDisclosure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Section $resource
 */
final class SectionResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     * `remaining_seats` is a display convenience only — see
     * Section::remainingSeats()'s docblock for why it is not authoritative.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     academic_term_id: int,
     *     subject_id: int,
     *     section_code: string,
     *     professor_id: ?int,
     *     professor_name?: ?string,
     *     schedule_days: ?string,
     *     starts_at_time: ?string,
     *     ends_at_time: ?string,
     *     room: ?string,
     *     capacity: int,
     *     capacity_source: string,
     *     viability_threshold: ?int,
     *     enrolled_count: int,
     *     remaining_seats: int,
     *     is_block_exclusive: ?bool,
     *     status: string,
     *     status_label: string
     * }
     */
    public function toArray(Request $request): array
    {
        // A Student does not see the professor until the enrollment and add/drop
        // windows are over (ProfessorVisibility); the keys stay, the values are null.
        $hideProfessor = ProfessorDisclosure::hiddenFrom($request, $this->resource->academic_term_id);

        return [
            'type' => 'section',
            'id' => $this->resource->id,
            'academic_term_id' => $this->resource->academic_term_id,
            'section_plan_id' => $this->resource->section_plan_id,
            'subject_id' => $this->resource->subject_id,
            'section_code' => $this->resource->section_code,
            'professor_id' => $hideProfessor ? null : $this->resource->professor_id,
            'professor_name' => $hideProfessor ? null : $this->resource->professor?->name,
            'schedule_days' => (new CanonicalScheduleDays)->normalize($this->resource->schedule_days),
            'starts_at_time' => $this->resource->starts_at_time,
            'ends_at_time' => $this->resource->ends_at_time,
            'room' => $this->resource->room,
            'modality' => $this->resource->modality?->value,
            'capacity' => $this->resource->capacity,
            'capacity_source' => $this->resource->capacity_source->value,
            'viability_threshold' => $this->resource->viability_threshold,
            'enrolled_count' => $this->resource->enrolled_count,
            'remaining_seats' => $this->resource->remainingSeats(),
            'is_block_exclusive' => $this->resource->is_block_exclusive,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'recommendation_source' => $this->resource->recommendation_source,
            'recommended_capacity' => $this->resource->recommended_capacity,
            'recommendation_prediction_run_id' => $this->resource->recommendation_prediction_run_id,
            'manual_override_reason' => $this->resource->manual_override_reason,
        ];
    }
}

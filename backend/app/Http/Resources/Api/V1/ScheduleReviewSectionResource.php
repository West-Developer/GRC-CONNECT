<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read Section $resource */
final class ScheduleReviewSectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'schedule_review_section',
            'id' => $this->resource->id,
            'section_code' => $this->resource->section_code,
            'subject_code' => $this->resource->subject->code,
            'subject_title' => $this->resource->subject->title,
            'units' => $this->resource->subject->units,
            'professor_id' => $this->resource->professor_id,
            'professor_name' => $this->resource->professor?->name,
            'schedule_days' => $this->resource->schedule_days,
            'starts_at_time' => $this->resource->starts_at_time,
            'ends_at_time' => $this->resource->ends_at_time,
            'room' => $this->resource->room,
            'modality' => $this->resource->modality?->value,
        ];
    }
}

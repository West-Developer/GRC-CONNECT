<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StudentSchedulePreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read StudentSchedulePreference $resource */
final class StudentSchedulePreferenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'student-schedule-preference',
            'id' => $this->resource->id,
            'student_id' => $this->resource->student_id,
            'preferred_days' => $this->resource->preferred_days,
            'preferred_time_block' => $this->resource->preferred_time_block->value,
            'preferred_time_block_label' => $this->resource->preferred_time_block->label(),
            'preferred_modality' => $this->resource->preferred_modality,
            'max_days_on_campus' => $this->resource->max_days_on_campus,
            'avoid_early_first_class' => $this->resource->avoid_early_first_class,
            'notes' => $this->resource->notes,
        ];
    }
}

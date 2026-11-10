<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FacultySpecialization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read FacultySpecialization $resource */
final class FacultySpecializationResource extends JsonResource
{
    /** @return array<string, int|string|null> */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'faculty-specialization',
            'id' => $this->resource->id,
            'professor_id' => $this->resource->professor_id,
            'subject_id' => $this->resource->subject_id,
            'proficiency' => $this->resource->proficiency->value,
            'proficiency_label' => $this->resource->proficiency->label(),
            'source' => $this->resource->source,
            'notes' => $this->resource->notes,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'decided_at' => $this->resource->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'decision_reason' => $this->resource->decision_reason,
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FacultyCurriculumSubjectPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read FacultyCurriculumSubjectPreference $resource */
final class FacultyCurriculumSubjectPreferenceResource extends JsonResource
{
    /** @return array<string, int|string> */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'faculty_curriculum_subject_preference',
            'id' => $this->resource->id,
            'professor_id' => $this->resource->professor_id,
            'curriculum_id' => $this->resource->curriculum_id,
            'subject_id' => $this->resource->subject_id,
            'semester' => $this->resource->semester,
            'rank' => $this->resource->rank,
            'origin' => $this->resource->origin,
        ];
    }
}

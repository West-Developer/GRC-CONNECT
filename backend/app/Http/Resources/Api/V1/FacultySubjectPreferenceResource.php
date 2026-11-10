<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FacultySubjectPreference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FacultySubjectPreference $resource
 */
final class FacultySubjectPreferenceResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     professor_id: int,
     *     academic_term_id: int,
     *     subject_id: int,
     *     rank: int
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'faculty_subject_preference',
            'id' => $this->resource->id,
            'professor_id' => $this->resource->professor_id,
            'academic_term_id' => $this->resource->academic_term_id,
            'subject_id' => $this->resource->subject_id,
            'rank' => $this->resource->rank,
        ];
    }
}

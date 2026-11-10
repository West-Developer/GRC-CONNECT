<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FacultyTeachingHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read FacultyTeachingHistory $resource */
final class FacultyTeachingHistoryResource extends JsonResource
{
    /** @return array<string, int|string|null> */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'faculty_teaching_history',
            'id' => $this->resource->id,
            'professor_id' => $this->resource->professor_id,
            'curriculum_id' => $this->resource->curriculum_id,
            'subject_id' => $this->resource->subject_id,
            'semester' => $this->resource->semester,
            'source_kind' => $this->resource->source_kind,
            'source_workbook' => $this->resource->source_workbook,
            'raw_alias' => $this->resource->raw_alias,
            'evidence_count' => $this->resource->evidence_count,
            'subject_code' => $this->resource->subject?->code,
            'subject_title' => $this->resource->subject?->title,
            'curriculum_name' => $this->resource->curriculum?->name,
        ];
    }
}

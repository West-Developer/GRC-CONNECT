<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AcademicTermCollegeWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read AcademicTermCollegeWorkflow $resource */
final class AcademicTermWorkflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'academic-term-workflow',
            'id' => $this->resource->id,
            'academic_term_id' => $this->resource->academic_term_id,
            'college' => $this->resource->college->value,
            'college_label' => $this->resource->college->label(),
            'stage' => $this->resource->stage->value,
            'stage_label' => $this->resource->stage->label(),
            'curriculum_completed_at' => $this->resource->curriculum_completed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'faculty_reviewed_at' => $this->resource->faculty_reviewed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'schedule_submitted_at' => $this->resource->schedule_submitted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

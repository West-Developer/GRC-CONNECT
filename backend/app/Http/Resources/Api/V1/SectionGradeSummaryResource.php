<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Section $resource
 */
final class SectionGradeSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, int|string> $progress */
        $progress = $this->resource->getAttribute('grade_progress');

        return [
            'type' => 'grade_section_summary',
            'section_id' => $this->resource->id,
            'section_code' => $this->resource->section_code,
            'subject' => [
                'id' => $this->resource->subject_id,
                'code' => $this->resource->subject->code,
                'title' => $this->resource->subject->title,
                'is_completion_only' => $this->resource->subject->isCompletionOnly(),
            ],
            'academic_term' => [
                'id' => $this->resource->academic_term_id,
                'school_year' => $this->resource->academicTerm->school_year,
                'semester' => $this->resource->academicTerm->semester,
            ],
            'schedule' => [
                'days' => $this->resource->schedule_days,
                'starts_at_time' => $this->resource->starts_at_time,
                'ends_at_time' => $this->resource->ends_at_time,
            ],
            ...$progress,
        ];
    }
}

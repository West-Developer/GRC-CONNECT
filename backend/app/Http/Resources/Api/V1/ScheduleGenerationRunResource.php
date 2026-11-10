<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ScheduleGenerationRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read ScheduleGenerationRun $resource */
final class ScheduleGenerationRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'schedule_generation_run',
            'id' => $this->resource->id,
            'academic_term_id' => $this->resource->academic_term_id,
            'prediction_run_id' => $this->resource->prediction_run_id,
            'college' => $this->resource->college,
            'status' => $this->resource->status->value,
            'warnings' => $this->resource->warnings ?? [],
            'error_summary' => $this->resource->error_summary,
            'started_at' => $this->resource->started_at?->toAtomString(),
            'completed_at' => $this->resource->completed_at?->toAtomString(),
            'created_at' => $this->resource->created_at?->toAtomString(),
        ];
    }
}

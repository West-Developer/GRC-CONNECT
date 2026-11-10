<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Subject $resource
 */
final class SubjectResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     * `is_completion_only` marks subjects graded Complete/Not-Complete only
     * (Leadership) — see `Subject::isCompletionOnly()`. `college`,
     * `paired_subject_id`, and `room_requirement` let a planning-role
     * consumer (the room scheduler's unscheduled-section picker) apply the
     * same lecture/laboratory pairing rule `Subject::isLectureComponent()`
     * already encodes, without a second endpoint.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     code: string,
     *     college: ?string,
     *     title: string,
     *     units: float,
     *     status: string,
     *     status_label: string,
     *     is_completion_only: bool,
     *     paired_subject_id: ?int,
     *     room_requirement: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'subject',
            'id' => $this->resource->id,
            'code' => $this->resource->code,
            'college' => $this->resource->college?->value,
            'title' => $this->resource->title,
            'units' => $this->resource->units,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'is_completion_only' => $this->resource->isCompletionOnly(),
            'paired_subject_id' => $this->resource->paired_subject_id,
            'room_requirement' => $this->resource->room_requirement,
        ];
    }
}

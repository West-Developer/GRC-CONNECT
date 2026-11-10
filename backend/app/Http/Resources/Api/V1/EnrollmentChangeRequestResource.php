<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EnrollmentChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EnrollmentChangeRequest $resource
 */
final class EnrollmentChangeRequestResource extends JsonResource
{
    /**
     * Exact key set. The decider's identity stays private to the audit log,
     * matching `WithdrawalRequestResource`'s precedent — `decision_reason`
     * is the one exception this table carries on the row itself (see the
     * migration's own docblock for why).
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     enrollment_id: int,
     *     student_number: string,
     *     request_type: string,
     *     request_type_label: string,
     *     subject_code: string,
     *     from_section_code: ?string,
     *     to_section_code: ?string,
     *     reason: string,
     *     status: string,
     *     status_label: string,
     *     decided_at: ?string,
     *     decision_reason: ?string,
     *     created_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'enrollment_change_request',
            'id' => $this->resource->id,
            'enrollment_id' => $this->resource->enrollment_id,
            'student_number' => $this->resource->enrollment->student->student_number,
            'request_type' => $this->resource->type->value,
            'request_type_label' => $this->resource->type->label(),
            'subject_code' => $this->resource->subject->code,
            'from_section_code' => $this->resource->fromSection?->section_code,
            'to_section_code' => $this->resource->toSection?->section_code,
            'reason' => $this->resource->reason,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'decided_at' => $this->resource->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'decision_reason' => $this->resource->decision_reason,
            'created_at' => $this->resource->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

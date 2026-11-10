<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EnrollmentSubjectWaiver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EnrollmentSubjectWaiver $resource
 */
final class SubjectWaiverResource extends JsonResource
{
    /**
     * Exact key set. Who granted or revoked stays in the audit log, matching
     * the other decision resources.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     student_id: int,
     *     subject_id: int,
     *     subject_code: string,
     *     subject_title: string,
     *     academic_term_id: int,
     *     reason: string,
     *     is_active: bool,
     *     granted_at: string,
     *     revoked_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'subject_waiver',
            'id' => $this->resource->id,
            'student_id' => $this->resource->student_id,
            'subject_id' => $this->resource->subject_id,
            'subject_code' => $this->resource->subject->code,
            'subject_title' => $this->resource->subject->title,
            'academic_term_id' => $this->resource->academic_term_id,
            'reason' => $this->resource->reason,
            'is_active' => $this->resource->revoked_at === null,
            'granted_at' => $this->resource->granted_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'revoked_at' => $this->resource->revoked_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

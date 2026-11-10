<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Identity\PersonName;
use App\Models\StudentProfileChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read StudentProfileChangeRequest $resource */
final class StudentProfileChangeRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $student = $this->resource->student;

        return [
            'type' => 'student_profile_change_request',
            'id' => $this->resource->id,
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->user->name,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'official' => [
                'name' => $student->user->name,
                'first_name' => $student->user->first_name ?? '',
                'middle_name' => $student->user->middle_name,
                'middle_initial' => $student->user->middle_initial,
                'last_name' => $student->user->last_name ?? '',
                'suffix' => $student->user->suffix,
                'email' => $student->user->email,
                'address' => $student->address,
            ],
            'requested' => [
                'name' => PersonName::compose(
                    $this->resource->requested_first_name,
                    $this->resource->requested_middle_initial,
                    $this->resource->requested_last_name,
                    $this->resource->requested_suffix,
                ),
                'first_name' => $this->resource->requested_first_name,
                'middle_name' => $this->resource->requested_middle_name,
                'middle_initial' => $this->resource->requested_middle_initial,
                'last_name' => $this->resource->requested_last_name,
                'suffix' => $this->resource->requested_suffix,
                'email' => $this->resource->requested_email,
                'address' => $this->resource->requested_address,
            ],
            'reason' => $this->resource->reason,
            'decision_notes' => $this->resource->decision_notes,
            'identity_verified_at' => $this->resource->identity_verified_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'requested_at' => $this->resource->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'decided_at' => $this->resource->decided_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

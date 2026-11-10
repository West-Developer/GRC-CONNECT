<?php

namespace App\Http\Resources\Api\V1\ItControl;

use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read StudentProfile $resource */
final class StudentAccountResource extends JsonResource
{
    /** @return array<string, int|string|bool|null> */
    public function toArray(Request $request): array
    {
        $currentTermEnrollment = $this->resource->enrollments->first();

        return [
            'type' => 'it-control-student-account',
            'id' => $this->resource->id,
            'user_id' => $this->resource->user_id,
            'student_number' => $this->resource->student_number,
            'name' => $this->resource->user->name,
            'first_name' => $this->resource->user->first_name,
            'last_name' => $this->resource->user->last_name,
            'suffix' => $this->resource->user->suffix,
            'email' => $this->resource->user->email,
            'program_code' => $this->resource->program->code,
            'college' => $this->resource->program->college?->value,
            'year_level' => $this->resource->year_level,
            'enrollment_category' => $this->resource->enrollment_category,
            'academic_standing' => $this->resource->academic_standing->value,
            'status' => $this->resource->user->status->value,
            'is_archived' => $this->resource->user->archived_at !== null,
            'current_term_enrollment_status' => $currentTermEnrollment?->status->value,
            'password_hint' => 'password',
        ];
    }
}

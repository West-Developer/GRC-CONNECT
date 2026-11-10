<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EnrollmentSubject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EnrollmentSubject $resource
 */
final class ClassRosterEntryResource extends JsonResource
{
    /**
     * Exact key set. `student_number` (never email or name) matches
     * `AcademicGradeResource`'s precedent — Faculty reading a roster gets no
     * more identifying information than a grade encoder already has.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     enrollment_id: int,
     *     section_id: int,
     *     section_code: string,
     *     subject_code: string,
     *     academic_term_id: int,
     *     student_id: int,
     *     student_number: string,
     *     student_name: string,
     *     status: string,
     *     status_label: string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'class_roster_entry',
            'id' => $this->resource->id,
            'enrollment_id' => $this->resource->enrollment_id,
            'section_id' => $this->resource->section_id,
            'section_code' => $this->resource->section->section_code,
            'subject_code' => $this->resource->section->subject->code,
            'academic_term_id' => $this->resource->section->academic_term_id,
            'student_id' => $this->resource->enrollment->student_id,
            'student_number' => $this->resource->enrollment->student->student_number,
            'student_name' => $this->resource->enrollment->student->user->name,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
        ];
    }
}

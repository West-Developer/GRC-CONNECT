<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AcademicGrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AcademicGrade $resource
 */
final class AcademicGradeResource extends JsonResource
{
    /**
     * Exact key set. `student_number` (never email or name) is exposed so
     * Faculty and the Registrar Head can identify whose record this is,
     * matching `EnrollmentResource`'s precedent. `mark` is the authoritative
     * value the API now accepts (see `App\Domain\Academic\GradeMark`);
     * `final_grade` survives as a derived numeric mirror, kept for readers
     * that predate the mark vocabulary — never cast to float (see
     * `AcademicGrade::casts()`), since PRD §17 leaves the grading scale
     * unconfirmed. `mark_label` is a pure function of `mark`
     * (`GradeMark::label()`) and is never stored.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     student_id: int,
     *     student_number: string,
     *     student_name: ?string,
     *     subject_id: int,
     *     subject_code: string,
     *     subject_title: string,
     *     section_id: ?int,
     *     section_code: ?string,
     *     professor_id: ?int,
     *     professor_name: ?string,
     *     college: ?string,
     *     academic_term_id: int,
     *     school_year: ?string,
     *     semester: ?string,
     *     mark: ?string,
     *     mark_label: ?string,
     *     final_grade: ?string,
     *     remarks: ?string,
     *     status: string,
     *     status_label: string,
     *     submitted_at: ?string,
     *     locked_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        $college = $this->resource->section?->sectionPlan?->college
            ?? $this->resource->subject?->college
            ?? $this->resource->student?->program?->college;

        return [
            'type' => 'academic_grade',
            'id' => $this->resource->id,
            'student_id' => $this->resource->student_id,
            'student_number' => $this->resource->student->student_number,
            'student_name' => $this->resource->student->user?->name,
            'subject_id' => $this->resource->subject_id,
            'subject_code' => $this->resource->subject->code,
            'subject_title' => $this->resource->subject->title,
            'section_id' => $this->resource->section_id,
            'section_code' => $this->resource->section?->section_code,
            'professor_id' => $this->resource->section?->professor_id ?? $this->resource->encoded_by,
            'professor_name' => $this->resource->section?->professor?->name ?? $this->resource->encoder?->name,
            'college' => $college instanceof \BackedEnum ? $college->value : (is_string($college) ? $college : null),
            'academic_term_id' => $this->resource->academic_term_id,
            'school_year' => $this->resource->academicTerm?->school_year,
            'semester' => $this->resource->academicTerm?->semester,
            'mark' => $this->resource->mark?->value,
            'mark_label' => $this->resource->mark?->label(),
            'final_grade' => $this->resource->final_grade,
            'remarks' => $this->resource->remarks,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'submitted_at' => $this->resource->submitted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'locked_at' => $this->resource->locked_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AcademicGrade;
use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read StudentProfile $resource
 */
final class GraduateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $grades = $this->resource->grades;
        $numericGrades = $grades->filter(fn (AcademicGrade $g) => $g->final_grade !== null && (float) $g->final_grade > 0);
        $finalGpa = $numericGrades->isNotEmpty()
            ? round($numericGrades->avg('final_grade'), 2)
            : null;

        return [
            'id' => $this->resource->id,
            'student_number' => $this->resource->student_number,
            'full_name' => $this->resource->user->name,
            'first_name' => $this->resource->user->first_name,
            'last_name' => $this->resource->user->last_name,
            'email' => $this->resource->user->email,
            'program_id' => $this->resource->program_id,
            'program_code' => $this->resource->program->code,
            'program_name' => $this->resource->program->name,
            'college' => $this->resource->program->college?->value,
            'curriculum_id' => $this->resource->curriculum_id,
            'curriculum_name' => $this->resource->curriculum?->name,
            'curriculum_version' => $this->resource->curriculum?->effective_school_year,
            'entry_year' => $this->resource->entry_year,
            'graduation_school_year' => $this->resource->graduation_school_year,
            'final_gpa' => $finalGpa,
        ];
    }
}

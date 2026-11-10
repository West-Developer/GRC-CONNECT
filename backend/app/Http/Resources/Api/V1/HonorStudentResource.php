<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class HonorStudentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'honor_student',
            'student_id' => $this->resource['student_id'],
            'student_number' => $this->resource['student_number'],
            'student_name' => $this->resource['student_name'],
            'program_id' => $this->resource['program_id'],
            'program_code' => $this->resource['program_code'],
            'program_name' => $this->resource['program_name'],
            'college' => $this->resource['college'],
            'year_level' => $this->resource['year_level'],
            'academic_term_id' => $this->resource['academic_term_id'],
            'school_year' => $this->resource['school_year'],
            'semester' => $this->resource['semester'],
            'gwa' => $this->resource['gwa'],
            'gwa_units' => $this->resource['gwa_units'],
            'excluded_subject_count' => $this->resource['excluded_subject_count'],
        ];
    }
}

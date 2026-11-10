<?php

namespace App\Http\Requests\Api\V1\AcademicGrade;

use Illuminate\Foundation\Http\FormRequest;

final class LockAllAcademicGradesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_term_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_terms,id'],
            'college' => ['sometimes', 'nullable', 'string', 'max:50'],
            'grade_ids' => ['sometimes', 'nullable', 'array'],
            'grade_ids.*' => ['integer', 'exists:academic_grades,id'],
        ];
    }
}

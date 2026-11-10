<?php

namespace App\Http\Requests\Api\V1\AcademicRecord;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `academic_term_id` is required — a grade slip is always one term.
 * `student_id` is optional; see `ShowProspectusRequest`.
 */
final class ShowGradeSlipRequest extends FormRequest
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
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'student_id' => ['sometimes', 'integer', 'exists:student_profiles,id'],
            'student_number' => ['sometimes', 'string', 'max:255'],
        ];
    }
}

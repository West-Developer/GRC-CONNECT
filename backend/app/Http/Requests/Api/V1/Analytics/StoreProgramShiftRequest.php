<?php

namespace App\Http\Requests\Api\V1\Analytics;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProgramShiftRequest extends FormRequest
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
            'student_number' => ['required', 'string', 'max:64', 'exists:student_profiles,student_number'],
            'to_program_id' => ['required', 'integer', 'exists:programs,id'],
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}

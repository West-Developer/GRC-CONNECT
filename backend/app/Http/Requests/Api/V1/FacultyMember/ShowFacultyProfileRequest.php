<?php

namespace App\Http\Requests\Api\V1\FacultyMember;

use Illuminate\Foundation\Http\FormRequest;

final class ShowFacultyProfileRequest extends FormRequest
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
            'academic_term_id' => ['sometimes', 'integer', 'exists:academic_terms,id'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\ClassRoster;

use Illuminate\Foundation\Http\FormRequest;

final class IndexClassRosterRequest extends FormRequest
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
            'section_id' => ['sometimes', 'integer', 'exists:sections,id'],
            'academic_term_id' => ['sometimes', 'integer', 'exists:academic_terms,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

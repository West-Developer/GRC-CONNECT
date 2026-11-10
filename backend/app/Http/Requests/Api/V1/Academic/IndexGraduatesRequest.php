<?php

namespace App\Http\Requests\Api\V1\Academic;

use Illuminate\Foundation\Http\FormRequest;

final class IndexGraduatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'graduation_school_year' => ['nullable', 'string', 'max:16'],
            'curriculum_id' => ['nullable', 'integer', 'exists:curricula,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

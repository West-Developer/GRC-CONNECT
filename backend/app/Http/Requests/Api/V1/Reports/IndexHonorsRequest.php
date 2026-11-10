<?php

namespace App\Http\Requests\Api\V1\Reports;

use App\Domain\Organization\CollegeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexHonorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'college' => ['sometimes', 'nullable', Rule::enum(CollegeCode::class)],
            'program_id' => ['sometimes', 'nullable', 'integer', 'exists:programs,id'],
            'year_level' => ['sometimes', 'nullable', 'integer', 'between:1, 4'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}

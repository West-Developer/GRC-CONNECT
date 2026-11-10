<?php

namespace App\Http\Requests\Api\V1\Dashboard;

use App\Domain\Dashboard\EnrollmentStatusGroup;
use App\Domain\Organization\CollegeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexEnrollmentStatusStudentsRequest extends FormRequest
{
    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `section_code` and `without_section` are two ways to name the section
     * level, so they cannot be combined.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_term_id' => ['sometimes', 'integer', 'exists:academic_terms,id'],
            'department' => ['required', Rule::enum(CollegeCode::class)],
            'section_code' => ['sometimes', 'string', 'max:32', 'prohibits:without_section'],
            'without_section' => ['sometimes', 'boolean'],
            'group' => ['sometimes', Rule::enum(EnrollmentStatusGroup::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ];
    }
}

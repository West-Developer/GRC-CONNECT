<?php

namespace App\Http\Requests\Api\V1\SectionPlan;

use Illuminate\Foundation\Http\FormRequest;

final class IndexSectionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'curriculum_id' => ['nullable', 'integer', 'exists:curricula,id'],
        ];
    }
}

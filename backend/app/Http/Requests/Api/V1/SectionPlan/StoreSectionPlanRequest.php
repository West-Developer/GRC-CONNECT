<?php

namespace App\Http\Requests\Api\V1\SectionPlan;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSectionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],
            'counts' => ['required', 'array', 'size:4'],
            'counts.*' => ['required', 'integer', 'between:0,99'],
            // Optional so an older client that only sends `counts` keeps
            // each year level's stored capacity instead of resetting it.
            'students_per_block' => ['sometimes', 'array', 'size:4'],
            'students_per_block.*' => ['required', 'integer', 'between:1,300'],
        ];
    }
}

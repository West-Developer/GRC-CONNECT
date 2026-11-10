<?php

namespace App\Http\Requests\Api\V1\SubjectOffering;

use Illuminate\Foundation\Http\FormRequest;

final class IndexSubjectOfferingRequest extends FormRequest
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
            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],
        ];
    }
}

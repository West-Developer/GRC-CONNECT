<?php

namespace App\Http\Requests\Api\V1\EligibleSubject;

use Illuminate\Foundation\Http\FormRequest;

final class IndexEligibleSubjectRequest extends FormRequest
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
            'enrollment_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

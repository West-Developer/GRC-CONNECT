<?php

namespace App\Http\Requests\Api\V1\Enrollment;

use Illuminate\Foundation\Http\FormRequest;

final class IndexSubjectWaiverRequest extends FormRequest
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
        ];
    }
}

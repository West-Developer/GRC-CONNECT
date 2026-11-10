<?php

namespace App\Http\Requests\Api\V1\Enrollment;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSubjectWaiverRequest extends FormRequest
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
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}

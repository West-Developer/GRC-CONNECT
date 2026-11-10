<?php

namespace App\Http\Requests\Api\V1\FacultySpecialization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideFacultySpecializationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required_if' => 'A reason is required for this action.',
        ];
    }
}

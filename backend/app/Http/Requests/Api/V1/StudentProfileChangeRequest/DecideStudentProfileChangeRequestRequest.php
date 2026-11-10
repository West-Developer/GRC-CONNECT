<?php

namespace App\Http\Requests\Api\V1\StudentProfileChangeRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DecideStudentProfileChangeRequestRequest extends FormRequest
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
            'identity_verified_in_person' => ['required', 'accepted'],
            'notes' => ['nullable', 'string', 'max:1000', 'required_if:action,reject'],
        ];
    }
}

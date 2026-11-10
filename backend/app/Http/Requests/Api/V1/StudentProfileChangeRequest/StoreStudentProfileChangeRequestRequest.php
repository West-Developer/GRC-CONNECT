<?php

namespace App\Http\Requests\Api\V1\StudentProfileChangeRequest;

use Illuminate\Foundation\Http\FormRequest;

final class StoreStudentProfileChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:255'],
            'suffix' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['required', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}

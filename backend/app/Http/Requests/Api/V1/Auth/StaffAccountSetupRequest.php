<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Domain\Organization\CollegeCode;
use App\Support\Auth\PasswordPolicy;
use App\Support\Privacy\PrivacyNotice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StaffAccountSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
            'name' => ['required', 'string', 'max:255'],
            'college' => ['nullable', Rule::enum(CollegeCode::class)],
            'masters_degree' => ['nullable', 'string', 'max:255'],
            'password' => [...PasswordPolicy::rules(), 'confirmed'],
            ...app(PrivacyNotice::class)->agreementRules(required: false),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return app(PrivacyNotice::class)->agreementMessages('you');
    }
}

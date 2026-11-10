<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\Auth\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordRequest extends FormRequest
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
            'password' => [...PasswordPolicy::rules(), 'confirmed'],
        ];
    }
}

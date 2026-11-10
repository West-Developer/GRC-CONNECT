<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Normalize before validation so the stored lowercase email matches
     * regardless of how the client cased or padded it.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    public function email(): string
    {
        return (string) $this->validated('email');
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }

    /**
     * Throttle key scoped to the submitted account and the caller's address,
     * so one targeted account cannot be brute-forced and one noisy client
     * cannot lock out unrelated users.
     */
    public function throttleKey(): string
    {
        return 'login|'.$this->email().'|'.$this->ip();
    }
}

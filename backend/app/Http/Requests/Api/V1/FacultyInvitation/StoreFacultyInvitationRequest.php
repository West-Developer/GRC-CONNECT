<?php

namespace App\Http\Requests\Api\V1\FacultyInvitation;

use Illuminate\Foundation\Http\FormRequest;

final class StoreFacultyInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ];
    }
}

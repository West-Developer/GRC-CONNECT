<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // "credential" matches Google Identity Services' own default POST
            // field name for the signed ID token.
            'credential' => ['required', 'string'],
        ];
    }
}

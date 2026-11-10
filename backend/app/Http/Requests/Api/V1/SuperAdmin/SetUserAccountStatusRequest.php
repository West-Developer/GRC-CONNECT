<?php

namespace App\Http\Requests\Api\V1\SuperAdmin;

use App\Domain\Identity\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SetUserAccountStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(UserStatus::class)],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
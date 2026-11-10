<?php

namespace App\Http\Requests\Api\V1\ItControl;

use App\Models\User;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ChangeAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && Gate::allows('manage-it-control-account', $target);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => [
                ...PasswordPolicy::rules(),
                'confirmed',
                // Bcrypt must not silently truncate a longer UTF-8 password.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The password is too long. Use a shorter password.');
                    }
                },
            ],
        ];
    }
}

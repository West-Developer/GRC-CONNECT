<?php

namespace App\Http\Requests\Api\V1\ItControl;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * IT Control edits the login identity of a Student or Faculty account: the name parts and the email address.
 * The middle name is not here on purpose: only Admission and the student ever see it (ADR 0053).
 */
final class UpdateAccountRequest extends FormRequest
{
    private const FIELDS = ['first_name', 'last_name', 'suffix', 'email'];

    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && Gate::allows('manage-it-control-account', $target);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'suffix' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => [
                'sometimes', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($target instanceof User ? $target->id : null),
            ],
        ];
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (array_intersect(array_keys($this->all()), self::FIELDS) === []) {
                    $validator->errors()->add('account', 'Change at least one of the name or email fields.');
                }
            },
        ];
    }
}

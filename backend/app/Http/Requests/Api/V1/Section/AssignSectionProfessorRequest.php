<?php

namespace App\Http\Requests\Api\V1\Section;

use App\Domain\Identity\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AssignSectionProfessorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `professor_id` is required but may be null: null removes the professor.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'professor_id' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Faculty->value)],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\FacultySpecialization;

use App\Domain\Faculty\SpecializationProficiency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFacultySpecializationProficiencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'proficiency' => ['required', 'string', Rule::enum(SpecializationProficiency::class)],
        ];
    }
}

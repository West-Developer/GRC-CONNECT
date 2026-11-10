<?php

namespace App\Http\Requests\Api\V1\ItControl;

use App\Domain\Identity\FacultyEmploymentType;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexFacultyAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:255'],
            'college' => ['sometimes', Rule::enum(CollegeCode::class)],
            'employment_type' => ['sometimes', Rule::enum(FacultyEmploymentType::class)],
            // `archived` is not a UserStatus: an archived account is disabled and leaves the other views (ADR 0054).
            'status' => ['sometimes', Rule::in([UserStatus::Active->value, UserStatus::Disabled->value, 'archived'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

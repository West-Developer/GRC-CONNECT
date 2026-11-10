<?php

namespace App\Http\Requests\Api\V1\ItControl;

use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexStudentAccountRequest extends FormRequest
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
            'program_id' => ['sometimes', 'integer', Rule::exists('programs', 'id')],
            'year_level' => ['sometimes', 'integer', 'min:1'],
            'enrollment_category' => ['sometimes', Rule::in(['regular', 'irregular'])],
            // `archived` is not a UserStatus: an archived account is disabled and leaves the other views (ADR 0054).
            'status' => ['sometimes', Rule::in([UserStatus::Active->value, UserStatus::Disabled->value, 'archived'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

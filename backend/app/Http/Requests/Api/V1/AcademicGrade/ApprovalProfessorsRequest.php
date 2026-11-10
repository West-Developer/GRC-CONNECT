<?php

namespace App\Http\Requests\Api\V1\AcademicGrade;

use App\Domain\Academic\GradeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApprovalProfessorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_term_id' => ['sometimes', 'integer', 'exists:academic_terms,id'],
            'status' => ['sometimes', Rule::in(array_map(
                fn (GradeStatus $status): string => $status->value,
                GradeStatus::cases(),
            ))],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'college' => ['sometimes', 'nullable', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

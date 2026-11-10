<?php

namespace App\Http\Requests\Api\V1\AcademicGrade;

use App\Domain\Academic\GradeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApprovalSectionsRequest extends FormRequest
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
            'professor_id' => ['required', 'integer', 'exists:users,id'],
            'academic_term_id' => ['sometimes', 'integer', 'exists:academic_terms,id'],
            'status' => ['sometimes', Rule::in(array_map(
                fn (GradeStatus $status): string => $status->value,
                GradeStatus::cases(),
            ))],
            'college' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}

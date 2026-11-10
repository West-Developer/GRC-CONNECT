<?php

namespace App\Http\Requests\Api\V1\Enrollment;

use App\Domain\Enrollment\EnrollmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexEnrollmentRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in(array_map(
                fn (EnrollmentStatus $status): string => $status->value,
                EnrollmentStatus::cases(),
            ))],
            'academic_term_id' => ['sometimes', 'integer', 'exists:academic_terms,id'],
            'enrollment_ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'enrollment_ids.*' => ['integer', 'min:1', 'distinct'],
            'without_queue_ticket' => ['sometimes', 'boolean'],
            'current_term' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'exceeds_unit_maximum' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

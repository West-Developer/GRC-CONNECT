<?php

namespace App\Http\Requests\Api\V1\EnrollmentDocument;

use App\Domain\Enrollment\EnrollmentDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexEnrollmentDocumentRequest extends FormRequest
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
            'enrollment_id' => ['sometimes', 'integer', 'exists:enrollments,id'],
            'student_number' => ['sometimes', 'string', 'max:100'],
            'student_name' => ['sometimes', 'string', 'max:100'],
            'document_type' => ['sometimes', Rule::in(array_map(
                fn (EnrollmentDocumentType $type): string => $type->value,
                EnrollmentDocumentType::cases(),
            ))],
            'document_number' => ['sometimes', 'string', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\StudentProfile;

use App\Actions\Identity\ListAdmissionStudentProfiles;
use App\Domain\Identity\StudentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            // Admission's freshman view (2026-10-16): who is a freshman, and where their account stands.
            'student_type' => ['sometimes', Rule::enum(StudentType::class)],
            'account_setup_status' => ['sometimes', Rule::in(ListAdmissionStudentProfiles::ACCOUNT_SETUP_STATUSES)],
            'intake' => ['sometimes', Rule::in([ListAdmissionStudentProfiles::INTAKE_PAY_FIRST])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}

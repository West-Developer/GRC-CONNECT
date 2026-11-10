<?php

namespace App\Http\Requests\Api\V1\StudentProfile;

use App\Domain\Identity\StudentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `student_type` decides which requirement lists apply (see `ListApplicableAdmissionRequirements`).
 * `year_level` is still accepted for older callers and is turned into a type the way Admission
 * intake used to derive it (Year 1 Freshman, Years 2-4 Transferee).
 */
final class IndexAdmissionRequirementTypesRequest extends FormRequest
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
            'student_type' => ['required_without:year_level', Rule::enum(StudentType::class)],
            'year_level' => ['required_without:student_type', 'integer', 'between:1,4'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Dashboard;

use App\Domain\Organization\CollegeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexEnrollmentStatusSectionsRequest extends FormRequest
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
            'department' => ['required', Rule::enum(CollegeCode::class)],
        ];
    }
}

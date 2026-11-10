<?php

namespace App\Http\Requests\Api\V1\Analytics;

use App\Actions\Analytics\BuildEnrollmentMovementReport;
use App\Domain\Organization\CollegeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexEnrollmentMovementRequest extends FormRequest
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
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'type' => ['required', Rule::in([
                BuildEnrollmentMovementReport::DROPS,
                BuildEnrollmentMovementReport::WITHDRAWALS,
                BuildEnrollmentMovementReport::SHIFTS,
            ])],
            'college' => ['sometimes', 'nullable', Rule::enum(CollegeCode::class)],
        ];
    }
}

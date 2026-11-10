<?php

namespace App\Http\Requests\Api\V1\Dashboard;

use App\Domain\Organization\CollegeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Unlike IndexEligibleSubjectRequest, academic_term_id is optional here —
 * the controller defaults to the currently active term, since a dashboard
 * is meant to be usable without the caller already knowing which term id to
 * ask for.
 */
final class IndexDashboardRequest extends FormRequest
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
            'year_level' => ['sometimes', 'nullable', 'integer', 'between:1,4'],
            'trend_school_year' => ['sometimes', 'nullable', 'string', 'max:32'],
            'trend_semester' => ['sometimes', 'nullable', 'string', 'max:32'],
            'trend_school_year_from' => ['sometimes', 'nullable', 'string', 'max:32'],
            'trend_school_year_to' => ['sometimes', 'nullable', 'string', 'max:32'],
            'department' => ['sometimes', 'nullable', Rule::enum(CollegeCode::class)],
        ];
    }
}

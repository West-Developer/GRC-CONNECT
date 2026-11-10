<?php

namespace App\Http\Requests\Api\V1\AcademicTerm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A Draft term's identifying label can be corrected before the enrollment
 * cycle begins. Enrollment dates remain owned by the schedule endpoint.
 */
final class UpdateDraftAcademicTermIdentityRequest extends FormRequest
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
            'school_year' => ['required', 'string', 'regex:/^\d{4}-\d{4}$/'],
            'semester' => ['required', 'string', Rule::in(['1st', '2nd'])],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\AcademicTerm;

use App\Models\AcademicTerm;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a term always starts it in `draft` (PRD Process 1) — the request
 * accepts no `status` field.
 */
final class StoreAcademicTermRequest extends FormRequest
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
            'enrollment_opens_at' => ['required', 'date'],
            'enrollment_closes_at' => ['required', 'date', 'after:enrollment_opens_at'],
            'add_drop_deadline_at' => ['required', 'date', 'after_or_equal:enrollment_opens_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $schoolYear = $this->input('school_year');
            $semester = $this->input('semester');

            if (! is_string($schoolYear) || ! is_string($semester)) {
                return;
            }

            $duplicateExists = AcademicTerm::query()
                ->where('school_year', $schoolYear)
                ->where('semester', $semester)
                ->exists();

            if ($duplicateExists) {
                $validator->errors()->add(
                    'school_year',
                    'A term for this school year and semester combination already exists.',
                );
            }
        });
    }
}

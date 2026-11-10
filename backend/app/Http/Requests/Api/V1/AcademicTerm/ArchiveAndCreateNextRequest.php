<?php

namespace App\Http\Requests\Api\V1\AcademicTerm;

use App\Domain\Organization\AcademicTermStatus;
use App\Models\AcademicTerm;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only the school year and semester of the *next* term. Enrollment dates
 * are deliberately absent — the Registrar sets those afterwards on the
 * enrollment schedule card, where the fixed 8:00 AM opening and the
 * per-audience windows are edited together.
 */
final class ArchiveAndCreateNextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $term = $this->route('academicTerm');
        if ($term instanceof AcademicTerm) {
            $next = $term->nextSequence();
            $this->merge([
                'school_year' => $this->filled('school_year') ? (string) $this->input('school_year') : $next['school_year'],
                'semester' => $this->filled('semester') ? (string) $this->input('semester') : $next['semester'],
            ]);
        }
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $schoolYear = $this->input('school_year');
            $semester = $this->input('semester');

            if (! is_string($schoolYear) || ! is_string($semester)) {
                return;
            }

            $term = $this->route('academicTerm');
            if ($term instanceof AcademicTerm && $term->school_year === $schoolYear && $term->semester === $semester) {
                $validator->errors()->add(
                    'school_year',
                    'The next academic term must be different from the term being archived.',
                );

                return;
            }

            $archivedDuplicateExists = AcademicTerm::query()
                ->where('school_year', $schoolYear)
                ->where('semester', $semester)
                ->where('status', AcademicTermStatus::Archived)
                ->exists();

            if ($archivedDuplicateExists) {
                $validator->errors()->add(
                    'school_year',
                    'A term for this school year and semester combination already exists and is archived.',
                );
            }
        });
    }
}

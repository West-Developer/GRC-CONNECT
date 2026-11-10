<?php

namespace App\Http\Requests\Api\V1\Analytics;

use App\Domain\Organization\CollegeCode;
use App\Models\AcademicTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class IndexAttritionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'baseline_academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'comparison_academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'college' => ['sometimes', 'nullable', Rule::enum(CollegeCode::class)],
            'program_id' => ['sometimes', 'nullable', 'integer', 'exists:programs,id'],
            'year_level' => ['sometimes', 'nullable', 'integer', 'between:1, 4'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $terms = AcademicTerm::query()
                ->whereIn('id', [
                    $this->integer('baseline_academic_term_id'),
                    $this->integer('comparison_academic_term_id'),
                ])
                ->get()
                ->keyBy('id');
            $baseline = $terms->get($this->integer('baseline_academic_term_id'));
            $comparison = $terms->get($this->integer('comparison_academic_term_id'));

            if (! $baseline instanceof AcademicTerm || ! $comparison instanceof AcademicTerm
                || $baseline->school_year !== $comparison->school_year
                || strtolower($baseline->semester) !== '1st'
                || strtolower($comparison->semester) !== '2nd') {
                $validator->errors()->add(
                    'comparison_academic_term_id',
                    'Attrition requires the 1st and 2nd semesters of the same school year.',
                );
            }
        });
    }
}

<?php

namespace App\Http\Requests\Api\V1\SectionGrade;

use App\Domain\Academic\CompletionOnlySubjectRule;
use App\Domain\Academic\GradeMark;
use App\Models\Section;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSectionGradeDraftsRequest extends FormRequest
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
            'grades' => ['required', 'array', 'min:1'],
            'grades.*.student_id' => ['required', 'integer', 'distinct', 'exists:student_profiles,id'],
            'grades.*.mark' => ['required', Rule::enum(GradeMark::class)->except(GradeMark::Withdrawn)],
            'grades.*.remarks' => ['sometimes', 'nullable', 'string'],
            'grades.*.subject_id' => ['prohibited'],
            'grades.*.academic_term_id' => ['prohibited'],
            'grades.*.section_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $section = $this->route('section');

            if (! $section instanceof Section) {
                return;
            }

            $section->loadMissing('subject');
            /** @var list<string> $prefixes */
            $prefixes = (array) config('enrollment.grading.completion_only_code_prefixes', []);
            $allowed = CompletionOnlySubjectRule::allowedMarks($section->subject->code, $prefixes);

            foreach ((array) $this->input('grades', []) as $index => $row) {
                if (! is_array($row) || ! isset($row['mark'])) {
                    continue;
                }

                $mark = GradeMark::tryFrom((string) $row['mark']);

                if ($mark !== null && ! in_array($mark, $allowed, true)) {
                    $validator->errors()->add(
                        "grades.{$index}.mark",
                        "{$section->subject->code} does not accept this grade mark.",
                    );
                }
            }
        });
    }
}

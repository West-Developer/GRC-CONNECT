<?php

namespace App\Http\Requests\Api\V1\SubjectOffering;

use App\Models\CurriculumSubject;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Full-replace shape, matching StoreCurriculumRequest's precedent: every
 * subject offering for the (academic_term_id, curriculum_id) pair is
 * submitted in one payload rather than diffed incrementally.
 */
final class ReplaceSubjectOfferingsRequest extends FormRequest
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
            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],
            'offerings' => ['present', 'array'],
            'offerings.*.subject_id' => ['required', 'integer', 'exists:subjects,id', 'distinct'],
            'offerings.*.year_level' => ['required', 'integer', 'min:1'],
            'offerings.*.semester' => ['required', 'string', 'max:255'],
            'offerings.*.min_section_capacity' => ['required', 'integer', 'min:1'],
            'offerings.*.max_section_capacity' => ['required', 'integer', 'min:1'],
            'offerings.*.recommended_sections' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertCapacityOrdering($validator);
            $this->assertEverySubjectIsPlacedInTheCurriculum($validator);
        });
    }

    private function assertCapacityOrdering(Validator $validator): void
    {
        /** @var list<array<string, mixed>> $offerings */
        $offerings = $this->input('offerings', []);

        foreach ($offerings as $index => $offering) {
            $min = $offering['min_section_capacity'] ?? null;
            $max = $offering['max_section_capacity'] ?? null;

            if (is_numeric($min) && is_numeric($max) && (int) $min > (int) $max) {
                $validator->errors()->add(
                    "offerings.{$index}.max_section_capacity",
                    'The maximum section capacity must be greater than or equal to the minimum.',
                );
            }
        }
    }

    private function assertEverySubjectIsPlacedInTheCurriculum(Validator $validator): void
    {
        $curriculumId = $this->input('curriculum_id');

        if (! is_numeric($curriculumId)) {
            return;
        }

        /** @var list<array<string, mixed>> $offerings */
        $offerings = $this->input('offerings', []);

        $subjectIds = array_values(array_filter(
            array_map(static fn (array $offering): mixed => $offering['subject_id'] ?? null, $offerings),
            static fn (mixed $subjectId): bool => is_numeric($subjectId),
        ));

        if ($subjectIds === []) {
            return;
        }

        $placedSubjectIds = CurriculumSubject::query()
            ->where('curriculum_id', $curriculumId)
            ->whereIn('subject_id', $subjectIds)
            ->pluck('subject_id')
            ->all();

        foreach ($offerings as $index => $offering) {
            $subjectId = $offering['subject_id'] ?? null;

            if (is_numeric($subjectId) && ! in_array((int) $subjectId, $placedSubjectIds, true)) {
                $validator->errors()->add(
                    "offerings.{$index}.subject_id",
                    'This subject is not placed in the selected curriculum.',
                );
            }
        }
    }

    /**
     * @return list<array{subject_id: int, year_level: int, semester: string, min_section_capacity: int, max_section_capacity: int, recommended_sections: int}>
     */
    public function offerings(): array
    {
        /** @var list<array<string, mixed>> $offerings */
        $offerings = $this->validated('offerings', []);

        return array_map(static fn (array $offering): array => [
            'subject_id' => (int) $offering['subject_id'],
            'year_level' => (int) $offering['year_level'],
            'semester' => (string) $offering['semester'],
            'min_section_capacity' => (int) $offering['min_section_capacity'],
            'max_section_capacity' => (int) $offering['max_section_capacity'],
            'recommended_sections' => (int) $offering['recommended_sections'],
        ], $offerings);
    }
}

<?php

namespace App\Http\Requests\Api\V1\Curriculum;

use App\Domain\Curriculum\PrerequisiteCycleDetector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Mirrors StoreCurriculumRequest's subject/prerequisite rules exactly — both
 * are full-replace payloads. `program_id` is intentionally absent: a
 * curriculum's owning program and effective school year do not change after
 * creation.
 */
final class UpdateCurriculumRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'subjects' => ['present', 'array'],
            'subjects.*.subject_id' => ['required', 'integer', 'exists:subjects,id', 'distinct'],
            'subjects.*.year_level' => ['required', 'integer', 'min:1'],
            'subjects.*.semester' => ['required', 'string', 'max:255'],
            'subjects.*.is_required' => ['required', 'boolean'],
            'subjects.*.prerequisites' => ['sometimes', 'array'],
            'subjects.*.prerequisites.*.prerequisite_subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'subjects.*.prerequisites.*.minimum_grade' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (app(PrerequisiteCycleDetector::class)->hasCycle($this->prerequisiteEdges())) {
                $validator->errors()->add(
                    'subjects',
                    'The submitted subjects contain a direct or transitive prerequisite cycle.',
                );
            }
        });
    }

    /**
     * @return list<array{subject_id: int, prerequisite_subject_id: int}>
     */
    private function prerequisiteEdges(): array
    {
        $edges = [];

        /** @var list<array<string, mixed>> $subjects */
        $subjects = $this->input('subjects', []);

        foreach ($subjects as $subject) {
            $subjectId = $subject['subject_id'] ?? null;

            if (! is_numeric($subjectId)) {
                continue;
            }

            /** @var list<array<string, mixed>> $prerequisites */
            $prerequisites = $subject['prerequisites'] ?? [];

            foreach ($prerequisites as $prerequisite) {
                $prerequisiteSubjectId = $prerequisite['prerequisite_subject_id'] ?? null;

                if (is_numeric($prerequisiteSubjectId)) {
                    $edges[] = [
                        'subject_id' => (int) $subjectId,
                        'prerequisite_subject_id' => (int) $prerequisiteSubjectId,
                    ];
                }
            }
        }

        return $edges;
    }

    /**
     * @return list<array{subject_id: int, year_level: int, semester: string, is_required: bool, prerequisites: list<array{prerequisite_subject_id: int, minimum_grade: string}>}>
     */
    public function subjects(): array
    {
        /** @var list<array<string, mixed>> $subjects */
        $subjects = $this->validated('subjects', []);

        return array_map(static function (array $subject): array {
            /** @var list<array<string, mixed>> $prerequisites */
            $prerequisites = $subject['prerequisites'] ?? [];

            return [
                'subject_id' => (int) $subject['subject_id'],
                'year_level' => (int) $subject['year_level'],
                'semester' => (string) $subject['semester'],
                'is_required' => (bool) $subject['is_required'],
                'prerequisites' => array_map(static fn (array $p): array => [
                    'prerequisite_subject_id' => (int) $p['prerequisite_subject_id'],
                    'minimum_grade' => (string) $p['minimum_grade'],
                ], $prerequisites),
            ];
        }, $subjects);
    }
}

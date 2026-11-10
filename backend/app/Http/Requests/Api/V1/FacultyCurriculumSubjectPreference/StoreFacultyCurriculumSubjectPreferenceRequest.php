<?php

namespace App\Http\Requests\Api\V1\FacultyCurriculumSubjectPreference;

use App\Models\Curriculum;
use App\Models\CurriculumSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFacultyCurriculumSubjectPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],
            'semester' => ['required', 'string', Rule::in(['1st', '2nd'])],
            'subject_id' => [
                'required', 'integer', 'exists:subjects,id',
                Rule::unique('faculty_curriculum_subject_preferences')->where(fn ($query) => $query
                    ->where('professor_id', $this->user()?->id)
                    ->where('curriculum_id', $this->input('curriculum_id'))
                    ->where('semester', $this->input('semester'))),
            ],
            'rank' => [
                'sometimes', 'integer', 'min:1',
                Rule::unique('faculty_curriculum_subject_preferences')->where(fn ($query) => $query
                    ->where('professor_id', $this->user()?->id)
                    ->where('curriculum_id', $this->input('curriculum_id'))
                    ->where('semester', $this->input('semester'))),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $curriculum = Curriculum::query()->with('program')->find($this->integer('curriculum_id'));
            if ($curriculum === null || $curriculum->program?->college !== $this->user()?->college) {
                $validator->errors()->add('curriculum_id', 'Select a curriculum in your college.');

                return;
            }

            $isPlaced = CurriculumSubject::query()
                ->where('curriculum_id', $curriculum->id)
                ->where('subject_id', $this->integer('subject_id'))
                ->where(function ($query): void {
                    $query->where('semester', $this->input('semester'))
                        ->orWhere('semester', '1st|2nd');
                })
                ->exists();
            if (! $isPlaced) {
                $validator->errors()->add('subject_id', 'Select a subject offered in the chosen curriculum and semester.');
            }
        });
    }
}

<?php

namespace App\Http\Requests\Api\V1\FacultyCurriculumSubjectPreference;

use App\Models\FacultyCurriculumSubjectPreference;
use Illuminate\Validation\Rule;

final class UpdateFacultyCurriculumSubjectPreferenceRequest extends StoreFacultyCurriculumSubjectPreferenceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var FacultyCurriculumSubjectPreference|null $preference */
        $preference = $this->route('facultyCurriculumPreference');

        return [
            'curriculum_id' => ['required', 'integer', 'exists:curricula,id'],
            'semester' => ['required', 'string', Rule::in(['1st', '2nd'])],
            'subject_id' => [
                'required', 'integer', 'exists:subjects,id',
                Rule::unique('faculty_curriculum_subject_preferences')->ignore($preference?->id)->where(fn ($query) => $query
                    ->where('professor_id', $this->user()?->id)
                    ->where('curriculum_id', $this->input('curriculum_id'))
                    ->where('semester', $this->input('semester'))),
            ],
            'rank' => [
                'required', 'integer', 'min:1',
                Rule::unique('faculty_curriculum_subject_preferences')->ignore($preference?->id)->where(fn ($query) => $query
                    ->where('professor_id', $this->user()?->id)
                    ->where('curriculum_id', $this->input('curriculum_id'))
                    ->where('semester', $this->input('semester'))),
            ],
        ];
    }
}

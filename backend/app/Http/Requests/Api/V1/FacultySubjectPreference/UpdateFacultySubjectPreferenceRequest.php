<?php

namespace App\Http\Requests\Api\V1\FacultySubjectPreference;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFacultySubjectPreferenceRequest extends FormRequest
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
            'subject_id' => [
                'required',
                'integer',
                'exists:subjects,id',
                Rule::unique('faculty_subject_preferences')->where(
                    fn ($query) => $query
                        ->where('professor_id', $this->user()?->id)
                        ->where('academic_term_id', $this->input('academic_term_id')),
                )->ignore($this->route('facultySubjectPreference')),
            ],
            'rank' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('faculty_subject_preferences')->where(
                    fn ($query) => $query
                        ->where('professor_id', $this->user()?->id)
                        ->where('academic_term_id', $this->input('academic_term_id')),
                )->ignore($this->route('facultySubjectPreference')),
            ],
        ];
    }
}

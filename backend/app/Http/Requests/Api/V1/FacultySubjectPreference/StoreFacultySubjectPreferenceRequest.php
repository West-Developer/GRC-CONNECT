<?php

namespace App\Http\Requests\Api\V1\FacultySubjectPreference;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `professor_id` is deliberately not a validated field — the controller
 * forces it to the authenticated user's ID.
 */
final class StoreFacultySubjectPreferenceRequest extends FormRequest
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
                ),
            ],
            'rank' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('faculty_subject_preferences')->where(
                    fn ($query) => $query
                        ->where('professor_id', $this->user()?->id)
                        ->where('academic_term_id', $this->input('academic_term_id')),
                ),
            ],
        ];
    }
}

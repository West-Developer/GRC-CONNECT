<?php

namespace App\Http\Requests\Api\V1\FacultySpecialization;

use App\Domain\Faculty\SpecializationProficiency;
use App\Domain\Identity\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFacultySpecializationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $actor = $this->user();
        $isChairAssigning = $actor?->role === UserRole::ProgramChair && $this->filled('professor_id');
        $targetProfessorId = $isChairAssigning ? (int) $this->input('professor_id') : $actor?->id;
        $isProgramChair = $actor?->role === UserRole::ProgramChair;

        return [
            'professor_id' => [
                Rule::requiredIf($isProgramChair),
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('role', UserRole::Faculty->value)
                        ->where('college', $actor?->college?->value),
                ),
            ],
            'subject_id' => [
                'required',
                'integer',
                Rule::exists('subjects', 'id')->where(
                    fn ($query) => $query->where('college', $actor?->college?->value),
                ),
                Rule::unique('faculty_specializations', 'subject_id')->where(
                    fn ($query) => $query->where('professor_id', $targetProfessorId),
                ),
            ],
            'proficiency' => ['sometimes', Rule::enum(SpecializationProficiency::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'professor_id.required' => 'Select the professor you are assigning this subject to.',
        ];
    }
}

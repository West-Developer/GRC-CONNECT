<?php

namespace App\Http\Requests\Api\V1\Curriculum;

use App\Domain\Curriculum\SemesterSlot;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCurriculumSubjectPlacementRequest extends FormRequest
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
        $user = $this->user();
        $college = $user instanceof User ? $user->college?->value : null;

        return [
            'source' => ['required', Rule::in(['new', 'existing'])],
            'year_level' => ['required', 'integer', 'min:1', 'max:4'],
            'semester' => ['required', Rule::enum(SemesterSlot::class)],
            'subject_id' => ['required_if:source,existing', 'integer', 'exists:subjects,id'],
            'equivalent_source_subject_id' => ['nullable', 'integer', 'exists:subjects,id', 'prohibited_unless:source,new'],
            'code' => [
                'required_if:source,new',
                'string',
                'max:255',
                Rule::unique('subjects', 'code')->where('college', $college),
            ],
            'title' => ['required_if:source,new', 'string', 'max:255'],
            'units' => ['required_if:source,new', 'numeric', 'min:0'],
        ];
    }
}

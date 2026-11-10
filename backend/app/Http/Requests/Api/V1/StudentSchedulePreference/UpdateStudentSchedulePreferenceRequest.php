<?php

namespace App\Http\Requests\Api\V1\StudentSchedulePreference;

use App\Domain\Enrollment\PreferredTimeBlock;
use App\Domain\Scheduling\SectionModality;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateStudentSchedulePreferenceRequest extends FormRequest
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
            'preferred_days' => ['nullable', 'array'],
            'preferred_days.*' => ['integer', 'between:1,6'],
            'preferred_time_block' => ['sometimes', Rule::enum(PreferredTimeBlock::class)],
            'preferred_modality' => ['nullable', Rule::enum(SectionModality::class)],
            'max_days_on_campus' => ['nullable', 'integer', 'between:1,6'],
            'avoid_early_first_class' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}

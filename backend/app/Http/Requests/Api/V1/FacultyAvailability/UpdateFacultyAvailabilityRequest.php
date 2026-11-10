<?php

namespace App\Http\Requests\Api\V1\FacultyAvailability;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFacultyAvailabilityRequest extends FormRequest
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
            'day_of_week' => ['required', 'integer', 'between:1,6'],
            'starts_at_time' => [
                'required',
                'date_format:H:i:s',
                Rule::unique('faculty_availabilities')->where(
                    fn ($query) => $query
                        ->where('professor_id', $this->user()?->id)
                        ->where('day_of_week', $this->integer('day_of_week'))
                        ->where('origin', 'declared'),
                )->ignore($this->route('facultyAvailability')),
            ],
            'ends_at_time' => ['required', 'date_format:H:i:s', 'after:starts_at_time'],
        ];
    }
}

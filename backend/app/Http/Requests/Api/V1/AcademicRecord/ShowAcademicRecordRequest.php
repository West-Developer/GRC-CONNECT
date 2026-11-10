<?php

namespace App\Http\Requests\Api\V1\AcademicRecord;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `student_id` is optional — identical shape to `ShowProspectusRequest`;
 * see that class's docblock for the "own profile" resolution rule.
 */
final class ShowAcademicRecordRequest extends FormRequest
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
            'student_id' => ['sometimes', 'integer', 'exists:student_profiles,id'],
            'student_number' => ['sometimes', 'string', 'max:255'],
        ];
    }
}

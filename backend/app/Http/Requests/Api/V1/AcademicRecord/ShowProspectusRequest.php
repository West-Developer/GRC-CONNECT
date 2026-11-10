<?php

namespace App\Http\Requests\Api\V1\AcademicRecord;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `student_id` is optional: a Student omits it to see their own; the
 * Registrar Head/Staff supply it to view another student's. The controller
 * resolves "own profile" when it is absent.
 */
final class ShowProspectusRequest extends FormRequest
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

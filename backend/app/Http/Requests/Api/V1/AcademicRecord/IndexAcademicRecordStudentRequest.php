<?php

namespace App\Http\Requests\Api\V1\AcademicRecord;

use Illuminate\Foundation\Http\FormRequest;

final class IndexAcademicRecordStudentRequest extends FormRequest
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
            'search' => ['required', 'string', 'min:1', 'max:255'],
            'by' => ['sometimes', 'string', 'in:all,student_number,name'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}

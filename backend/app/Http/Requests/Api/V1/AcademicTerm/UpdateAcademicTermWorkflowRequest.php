<?php

namespace App\Http\Requests\Api\V1\AcademicTerm;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAcademicTermWorkflowRequest extends FormRequest
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
            'action' => ['required', 'string', 'in:start_curriculum_preparation,complete_curriculum_preparation,complete_faculty_input'],
        ];
    }
}

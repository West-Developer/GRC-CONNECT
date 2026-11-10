<?php

namespace App\Http\Requests\Api\V1\SectionGrade;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitSectionGradesRequest extends FormRequest
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
        return [];
    }
}

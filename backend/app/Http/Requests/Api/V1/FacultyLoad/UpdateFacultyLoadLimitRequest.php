<?php

namespace App\Http\Requests\Api\V1\FacultyLoad;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFacultyLoadLimitRequest extends FormRequest
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
            'max_units' => ['required', 'numeric', 'gt:0', 'max:99'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\AcademicTerm;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAcademicTermRequest extends FormRequest
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
            'action' => ['required', 'string', 'in:open_enrollment,close,archive'],
        ];
    }
}

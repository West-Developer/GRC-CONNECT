<?php

namespace App\Http\Requests\Api\V1\StudentProfile;

use App\Domain\Identity\AdmissionRequirementCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAdmissionRequirementTypeRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'category' => ['required', Rule::enum(AdmissionRequirementCategory::class)],
        ];
    }
}

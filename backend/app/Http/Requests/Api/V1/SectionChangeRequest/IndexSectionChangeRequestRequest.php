<?php

namespace App\Http\Requests\Api\V1\SectionChangeRequest;

use App\Domain\Scheduling\SectionChangeRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexSectionChangeRequestRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(SectionChangeRequestStatus::class)],
            'section_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

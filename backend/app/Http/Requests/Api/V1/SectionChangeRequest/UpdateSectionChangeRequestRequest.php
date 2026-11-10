<?php

namespace App\Http\Requests\Api\V1\SectionChangeRequest;

use App\Actions\Scheduling\DecideSectionChangeRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSectionChangeRequestRequest extends FormRequest
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
            'action' => ['required', Rule::in([
                DecideSectionChangeRequest::APPROVE,
                DecideSectionChangeRequest::REJECT,
                DecideSectionChangeRequest::CANCEL,
            ])],
            'decision_reason' => ['nullable', 'string', 'max:1000', 'required_if:action,'.DecideSectionChangeRequest::REJECT],
        ];
    }
}

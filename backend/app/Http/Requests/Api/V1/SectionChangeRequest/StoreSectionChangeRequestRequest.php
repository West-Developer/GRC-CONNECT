<?php

namespace App\Http\Requests\Api\V1\SectionChangeRequest;

use App\Domain\Scheduling\PublishedSectionLock;
use App\Domain\Scheduling\SectionModality;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSectionChangeRequestRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.schedule_days' => ['sometimes', 'nullable', 'string', 'max:255'],
            'changes.starts_at_time' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'changes.ends_at_time' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            'changes.room' => ['sometimes', 'nullable', 'string', 'max:255'],
            'changes.modality' => ['sometimes', 'nullable', Rule::enum(SectionModality::class)],
            'changes.capacity' => ['sometimes', 'integer', 'min:1'],
            'changes.viability_threshold' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $changes = $this->input('changes');
            if (! is_array($changes)) {
                return;
            }

            foreach (array_diff(array_keys($changes), PublishedSectionLock::CHANGEABLE_FIELDS) as $field) {
                $validator->errors()->add("changes.{$field}", 'This field cannot be changed through a change request.');
            }
        });
    }
}

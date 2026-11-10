<?php

namespace App\Http\Requests\Api\V1\SubjectWithdrawal;

use Illuminate\Foundation\Http\FormRequest;

final class StoreSubjectWithdrawalRequest extends FormRequest
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
            'enrollment_subject_ids' => ['required', 'array', 'min:1', 'max:20'],
            'enrollment_subject_ids.*' => ['required', 'integer', 'distinct', 'exists:enrollment_subjects,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}

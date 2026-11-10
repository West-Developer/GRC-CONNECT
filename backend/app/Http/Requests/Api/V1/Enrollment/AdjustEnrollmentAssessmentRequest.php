<?php

namespace App\Http\Requests\Api\V1\Enrollment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Cashier submits every existing assessment line. Labels and categories
 * are deliberately not client-editable: only a validated amount/rate may be
 * corrected, and the server recomputes the total.
 */
final class AdjustEnrollmentAssessmentRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.amount' => ['sometimes', 'nullable', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'items.*.unit_amount' => ['sometimes', 'nullable', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
        ];
    }
}

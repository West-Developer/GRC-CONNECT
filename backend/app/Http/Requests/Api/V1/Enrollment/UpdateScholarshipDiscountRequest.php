<?php

namespace App\Http\Requests\Api\V1\Enrollment;

use App\Domain\Billing\ScholarshipTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only the three tiers of ADR 0025 exist; the percentage is chosen from them,
 * never typed as a free amount.
 */
final class UpdateScholarshipDiscountRequest extends FormRequest
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
            'percentage' => [
                'required',
                'integer',
                Rule::in(array_map(fn (ScholarshipTier $tier): int => $tier->value, ScholarshipTier::cases())),
            ],
        ];
    }
}

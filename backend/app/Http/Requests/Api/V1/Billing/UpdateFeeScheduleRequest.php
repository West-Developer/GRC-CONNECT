<?php

namespace App\Http\Requests\Api\V1\Billing;

use App\Domain\Identity\UserRole;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateFeeScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::AccountingStaff;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'tuition_rate_per_unit' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'miscellaneous_fees' => ['required', 'array'],
            'miscellaneous_fees.*.id' => ['nullable', 'integer'],
            'miscellaneous_fees.*.label' => ['required', 'string', 'max:255'],
            'miscellaneous_fees.*.amount' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'miscellaneous_fees.*.semester' => ['nullable', 'in:1st,2nd'],
            'miscellaneous_fees.*.program_codes' => ['nullable', 'array'],
            'miscellaneous_fees.*.program_codes.*' => ['string', 'max:50'],
            'miscellaneous_fees.*.is_active' => ['nullable', 'boolean'],
            'miscellaneous_fees.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Billing;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Registrar Head's edit of the full-payment discount (ADR 0046). The percentage has at most
 * one decimal place because it is stored in an assessment line's `quantity` (`decimal(6,1)`).
 * Both date keys must be sent: `null` clears a date, so leaving one out is a mistake rather than
 * "keep what is there".
 */
final class UpdatePaymentDiscountSettingRequest extends FormRequest
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
            'percentage' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,1'],
            'valid_from' => ['present', 'nullable', 'date_format:Y-m-d'],
            'valid_until' => ['present', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('valid_from');
            $until = $this->input('valid_until');

            if (is_string($from) && is_string($until) && $until < $from) {
                $validator->errors()->add('valid_until', 'The end date cannot be before the start date.');
            }
        });
    }
}

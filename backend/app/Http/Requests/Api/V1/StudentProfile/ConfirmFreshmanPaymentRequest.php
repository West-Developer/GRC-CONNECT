<?php

namespace App\Http\Requests\Api\V1\StudentProfile;

use App\Actions\Enrollment\FreshmanPaymentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A freshman's first payment (ADR 0043): an amount of at least the configured minimum
 * and an optional receipt reference. There is no promissory-note field — a freshman does
 * not need one. Amounts never go through binary floating point: they are validated as
 * numeric and normalized to an exact two-decimal string by `FreshmanPaymentRules`, which
 * the action applies again.
 */
final class ConfirmFreshmanPaymentRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:'.FreshmanPaymentRules::minimumAmount(), 'max:99999999.99'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            // ADR 0046. `full` pays the discounted estimate; `amount` must equal the quote the
            // Cashier was shown. Absent or `installment` is the existing minimum-payment rule.
            'payment_type' => ['sometimes', 'string', Rule::in(['full', 'installment'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.min' => 'A freshman\'s first payment must be at least PHP '.number_format((float) FreshmanPaymentRules::minimumAmount(), 2).'.',
        ];
    }
}

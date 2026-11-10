<?php

namespace App\Http\Requests\Api\V1\StudentAccount;

use App\Domain\Identity\FinancialStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAccountPaymentRequest extends FormRequest
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
            'amount' => ['nullable', 'numeric', 'gte:0', 'lte:99999999.99'],
            'financial_status' => ['nullable', 'string', Rule::enum(FinancialStatus::class)],
        ];
    }
}

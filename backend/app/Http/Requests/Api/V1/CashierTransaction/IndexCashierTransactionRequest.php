<?php

namespace App\Http\Requests\Api\V1\CashierTransaction;

use Illuminate\Foundation\Http\FormRequest;

final class IndexCashierTransactionRequest extends FormRequest
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
            'student_number' => ['sometimes', 'string', 'max:255'],
            'processed_on' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

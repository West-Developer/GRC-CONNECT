<?php

namespace App\Http\Requests\Api\V1\CashierPaymentCandidate;

use Illuminate\Foundation\Http\FormRequest;

final class ShowCashierPaymentCandidateRequest extends FormRequest
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
            'student_number' => ['required', 'string', 'max:255'],
        ];
    }
}

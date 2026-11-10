<?php

namespace App\Http\Requests\Api\V1\WithdrawalRequest;

use App\Domain\Enrollment\WithdrawalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexWithdrawalRequestRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in(array_map(
                fn (WithdrawalStatus $status): string => $status->value,
                WithdrawalStatus::cases(),
            ))],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

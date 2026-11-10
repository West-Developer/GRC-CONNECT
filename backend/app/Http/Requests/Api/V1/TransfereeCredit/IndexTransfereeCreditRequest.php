<?php

namespace App\Http\Requests\Api\V1\TransfereeCredit;

use App\Domain\Academic\TransfereeCreditStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexTransfereeCreditRequest extends FormRequest
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
                fn (TransfereeCreditStatus $status): string => $status->value,
                TransfereeCreditStatus::cases(),
            ))],
            'student_id' => ['sometimes', 'integer', 'exists:student_profiles,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

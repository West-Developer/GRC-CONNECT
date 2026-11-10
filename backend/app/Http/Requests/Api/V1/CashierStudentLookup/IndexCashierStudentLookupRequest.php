<?php

namespace App\Http\Requests\Api\V1\CashierStudentLookup;

use Illuminate\Foundation\Http\FormRequest;

final class IndexCashierStudentLookupRequest extends FormRequest
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
            'search' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}

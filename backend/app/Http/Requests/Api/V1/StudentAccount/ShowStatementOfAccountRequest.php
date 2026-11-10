<?php

namespace App\Http\Requests\Api\V1\StudentAccount;

use Illuminate\Foundation\Http\FormRequest;

final class ShowStatementOfAccountRequest extends FormRequest
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
            'academic_term_id' => ['sometimes', 'integer', 'exists:academic_terms,id'],
        ];
    }
}

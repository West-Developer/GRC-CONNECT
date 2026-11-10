<?php

namespace App\Http\Requests\Api\V1\TorDocument;

use Illuminate\Foundation\Http\FormRequest;

final class IndexTorDocumentRequest extends FormRequest
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
            'student_id' => ['sometimes', 'integer', 'exists:student_profiles,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

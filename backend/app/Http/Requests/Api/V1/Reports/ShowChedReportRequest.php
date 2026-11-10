<?php

namespace App\Http\Requests\Api\V1\Reports;

use Illuminate\Foundation\Http\FormRequest;

final class ShowChedReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function academicTermId(): int
    {
        return $this->integer('academic_term_id');
    }

    /** The words typed in the search box, or null when it is empty. */
    public function searchTerm(): ?string
    {
        $search = trim((string) $this->input('search', ''));

        return $search === '' ? null : $search;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}

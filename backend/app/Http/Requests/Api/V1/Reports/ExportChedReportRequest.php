<?php

namespace App\Http\Requests\Api\V1\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExportChedReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function academicTermId(): int
    {
        return $this->integer('academic_term_id');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'format' => ['required', Rule::in(['csv', 'xlsx'])],
        ];
    }
}

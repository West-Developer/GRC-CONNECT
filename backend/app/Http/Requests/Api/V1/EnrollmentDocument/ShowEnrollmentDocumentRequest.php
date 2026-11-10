<?php

namespace App\Http\Requests\Api\V1\EnrollmentDocument;

use Illuminate\Foundation\Http\FormRequest;

final class ShowEnrollmentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}

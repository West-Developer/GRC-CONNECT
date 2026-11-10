<?php

namespace App\Http\Requests\Api\V1\TorDocument;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTorDocumentRequest extends FormRequest
{
    /** Largest TOR file accepted, in kilobytes (a multi-page scan). */
    public const MAX_KILOBYTES = 8192;

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
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose your Transcript of Records file to upload.',
            'file.mimes' => 'Upload the TOR as a PDF, JPG or PNG file.',
            'file.max' => 'The file is too large. Upload a TOR of 8 MB or less.',
            'file.uploaded' => 'The file could not be uploaded. Try a file of 8 MB or less.',
        ];
    }
}

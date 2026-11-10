<?php

namespace App\Http\Requests\Api\V1\QueueKioskCredential;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateQueueKioskCredentialRequest extends FormRequest
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
        return ['password' => ['required', 'string', 'min:8', 'max:255']];
    }
}

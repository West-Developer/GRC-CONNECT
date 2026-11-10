<?php

namespace App\Http\Requests\Api\V1\Notification;

use Illuminate\Foundation\Http\FormRequest;

final class IndexNotificationRequest extends FormRequest
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
            'unread_only' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $unreadOnly = $this->query('unread_only');

        if ($unreadOnly === 'true' || $unreadOnly === 'false') {
            $this->merge(['unread_only' => $unreadOnly === 'true']);
        }
    }
}

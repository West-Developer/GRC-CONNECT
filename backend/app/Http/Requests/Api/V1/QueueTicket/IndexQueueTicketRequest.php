<?php

namespace App\Http\Requests\Api\V1\QueueTicket;

use App\Domain\Enrollment\QueueTicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexQueueTicketRequest extends FormRequest
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
            'queue_date' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in(array_map(
                fn (QueueTicketStatus $status): string => $status->value,
                QueueTicketStatus::cases(),
            ))],
            'cycle' => ['sometimes', Rule::in(['open'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}

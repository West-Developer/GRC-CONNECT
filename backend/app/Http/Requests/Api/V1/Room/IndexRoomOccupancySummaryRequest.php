<?php

namespace App\Http\Requests\Api\V1\Room;

use Illuminate\Foundation\Http\FormRequest;

final class IndexRoomOccupancySummaryRequest extends FormRequest
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
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
        ];
    }
}

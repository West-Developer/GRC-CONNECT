<?php

namespace App\Http\Requests\Api\V1\Room;

use Illuminate\Foundation\Http\FormRequest;

final class IndexRoomOccupancyRequest extends FormRequest
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
            'room' => ['required', 'string', 'exists:room_catalog_entries,name'],
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
        ];
    }
}

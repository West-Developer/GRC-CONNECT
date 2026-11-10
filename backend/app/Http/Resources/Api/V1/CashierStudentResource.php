<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Cashier's student search: just enough to pick the right
 * student and open their account. No contact data.
 *
 * @property-read StudentProfile $resource
 */
final class CashierStudentResource extends JsonResource
{
    /**
     * @return array{type: string, student_id: int, student_number: string, student_name: string, year_level: int, financial_status: string, financial_status_label: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'cashier_student',
            'student_id' => $this->resource->id,
            'student_number' => $this->resource->student_number,
            'student_name' => $this->resource->user->name,
            'year_level' => $this->resource->year_level,
            'financial_status' => $this->resource->financial_status->value ?? 'payee',
            'financial_status_label' => $this->resource->financial_status?->label() ?? 'Payee',
        ];
    }
}

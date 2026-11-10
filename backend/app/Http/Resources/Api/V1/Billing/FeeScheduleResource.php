<?php

namespace App\Http\Resources\Api\V1\Billing;

use App\Models\FeeSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read FeeSchedule $resource */
final class FeeScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'category' => $this->resource->category,
            'semester' => $this->resource->semester,
            'label' => $this->resource->label,
            'amount' => (string) $this->resource->amount,
            'program_codes' => $this->resource->program_codes,
            'is_active' => (bool) $this->resource->is_active,
            'sort_order' => (int) $this->resource->sort_order,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}

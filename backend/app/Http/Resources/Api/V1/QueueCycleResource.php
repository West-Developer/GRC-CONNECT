<?php

namespace App\Http\Resources\Api\V1;

use App\Models\QueueCycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read QueueCycle $resource
 */
final class QueueCycleResource extends JsonResource
{
    /**
     * @return array{type: string, id: int, opened_on: string, status: string, status_label: string, cut_off_at: ?string, cut_off_service_date: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'queue_cycle',
            'id' => $this->resource->id,
            'opened_on' => $this->resource->opened_on->toDateString(),
            'status' => $this->resource->status()->value,
            'status_label' => $this->resource->status()->label(),
            'cut_off_at' => $this->resource->cut_off_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'cut_off_service_date' => $this->resource->cut_off_service_date?->toDateString(),
        ];
    }
}

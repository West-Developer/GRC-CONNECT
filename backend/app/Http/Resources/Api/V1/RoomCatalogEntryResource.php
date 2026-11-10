<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RoomCatalogEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read RoomCatalogEntry $resource */
final class RoomCatalogEntryResource extends JsonResource
{
    /** @return array{type: string, id: int, name: string} */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'room_option',
            'id' => $this->resource->id,
            'name' => $this->resource->name,
        ];
    }
}

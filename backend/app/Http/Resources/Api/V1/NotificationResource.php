<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Notification $resource
 */
final class NotificationResource extends JsonResource
{
    /**
     * Exact key set. The owning user ID is deliberately never exposed.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     notification_type: string,
     *     message: string,
     *     read_at: ?string,
     *     created_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'notification',
            'id' => $this->resource->id,
            'notification_type' => $this->resource->type->value,
            'message' => $this->resource->message,
            'read_at' => $this->resource->read_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->resource->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

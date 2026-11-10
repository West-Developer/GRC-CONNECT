<?php

namespace App\Http\Resources\Api\V1\ItControl;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What IT Control gets back after editing, archiving or restoring a Student or Faculty account. Never the
 * middle name, a password or a token.
 *
 * @property-read User $resource
 */
final class AccountResource extends JsonResource
{
    /** @return array<string, int|string|bool|null> */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'it-control-account',
            'id' => $this->resource->id,
            'role' => $this->resource->role->value,
            'name' => $this->resource->name,
            'first_name' => $this->resource->first_name,
            'last_name' => $this->resource->last_name,
            'suffix' => $this->resource->suffix,
            'email' => $this->resource->email,
            'status' => $this->resource->status->value,
            'is_archived' => $this->resource->archived_at !== null,
            'archived_at' => $this->resource->archived_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

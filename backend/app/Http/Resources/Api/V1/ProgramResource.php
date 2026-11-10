<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Program $resource
 */
final class ProgramResource extends JsonResource
{
    /**
     * Exact key set. No attribute is passed through implicitly.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     code: string,
     *     name: string,
     *     status: string,
     *     status_label: string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'program',
            'id' => $this->resource->id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class HealthResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     service: string,
     *     status: string,
     *     api_version: string,
     *     generated_at: string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->resource['type'],
            'service' => $this->resource['service'],
            'status' => $this->resource['status'],
            'api_version' => $this->resource['api_version'],
            'generated_at' => $this->resource['generated_at'],
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{email: string, password: string} $resource
 */
final class QueueKioskCredentialResource extends JsonResource
{
    /**
     * @return array{type: string, email: string, password: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'queue_kiosk_credential',
            'email' => $this->resource['email'],
            'password' => $this->resource['password'],
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
    }
}

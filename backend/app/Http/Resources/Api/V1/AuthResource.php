<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

/**
 * The single path through which a bearer token may leave the API (PRD §9.1).
 *
 * @property-read array{user: User, token: NewAccessToken, expiresAt: ?CarbonImmutable} $resource
 */
final class AuthResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     token: string,
     *     token_type: string,
     *     expires_at: ?string,
     *     user: array<string, mixed>
     * }
     */
    public function toArray(Request $request): array
    {
        $expiresAt = $this->resource['expiresAt'];

        return [
            'type' => 'auth-session',
            'token' => $this->resource['token']->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->utc()->format('Y-m-d\TH:i:s\Z'),
            'user' => UserResource::make($this->resource['user'])->toArray($request),
        ];
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        // `private` in addition to `no-store` so no shared cache may retain a
        // response body that contains a bearer token.
        $response->headers->set('Cache-Control', 'no-store, private');
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Support\Privacy\PrivacyNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read User $resource
 */
final class UserResource extends JsonResource
{
    /**
     * Exact key set. The password hash and token relationship are never
     * exposed, and no attribute is passed through implicitly.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     name: string,
     *     email: string,
     *     role: string,
     *     role_label: string,
     *     college: ?string,
     *     status: string,
     *     privacy_notice: array{version: string, accepted_version: ?string, accepted_at: ?string, requires_acceptance: bool}
     * }
     */
    public function toArray(Request $request): array
    {
        // ADR 0055: whether this person has agreed to the current Data Privacy Notice. The portal shows the
        // notice before anything else while `requires_acceptance` is true.
        $notice = app(PrivacyNotice::class);
        $latest = $notice->latestAcceptance($this->resource);

        $data = [
            'type' => 'user',
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'role' => $this->resource->role->value,
            'role_label' => $this->resource->role->label(),
            'college' => $this->resource->college?->value,
            'status' => $this->resource->status->value,
            'privacy_notice' => [
                'version' => $notice->version(),
                'accepted_version' => $latest?->notice_version,
                'accepted_at' => $latest?->accepted_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'requires_acceptance' => $notice->requiresAcceptance($this->resource),
            ],
        ];

        if ($this->resource->isSuperAdmin()) {
            $context = $this->resource->actingContext();
            $data['acting_context'] = $context !== null
                ? [
                    'role' => $context->role->value,
                    'college' => $context->college?->value,
                ]
                : null;
        }

        return $data;
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
    }
}

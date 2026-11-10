<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row per actor in the audit screen's first level. The resource wraps an
 * `AuditLog` row that carries the grouped `entries_count` and
 * `last_activity_at`; it is never one real audit entry.
 *
 * @property-read AuditLog $resource
 */
final class AuditActorResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     actor_user_id: int,
     *     actor_name: string,
     *     actor_role: string,
     *     actor_role_label: string,
     *     entries_count: int,
     *     last_activity_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        $last = $this->resource->getAttribute('last_activity_at');

        return [
            'type' => 'audit_actor',
            'actor_user_id' => $this->resource->actor_user_id,
            'actor_name' => $this->resource->actor->name,
            'actor_role' => $this->resource->actor->role->value,
            'actor_role_label' => $this->resource->actor->role->label(),
            'entries_count' => (int) $this->resource->getAttribute('entries_count'),
            'last_activity_at' => $last === null ? null : CarbonImmutable::parse((string) $last, 'UTC')->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

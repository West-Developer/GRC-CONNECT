<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Identity\UserRole;
use App\Models\AuditLog;
use App\Support\Audit\AuditChangeFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AuditLog $resource
 */
final class AuditLogResource extends JsonResource
{
    /**
     * Exact safe key set. This privileged read (Registrar Head only) names the
     * actor and their role (stakeholder Doc 14); email addresses are still
     * never included, and `changes` hides any field that looks like a name or
     * contact detail.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     actor_user_id: int,
     *     actor_name: string,
     *     actor_role: string,
     *     actor_role_label: string,
     *     acting_role: ?string,
     *     acting_role_label: ?string,
     *     acting_college: ?string,
     *     action: string,
     *     auditable_type: string,
     *     auditable_id: ?int,
     *     before_values: ?array<string, mixed>,
     *     after_values: ?array<string, mixed>,
     *     changes: list<array{field: string, label: string, old: mixed, new: mixed, changed: bool}>,
     *     reason: ?string,
     *     request_id: string,
     *     ip_address: ?string,
     *     created_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'audit_log',
            'id' => $this->resource->id,
            'actor_user_id' => $this->resource->actor_user_id,
            'actor_name' => $this->resource->actor->name,
            'actor_role' => $this->resource->actor->role->value,
            'actor_role_label' => $this->resource->actor->role->label(),
            'acting_role' => $this->resource->acting_role,
            'acting_role_label' => $this->resource->acting_role !== null
                ? UserRole::from($this->resource->acting_role)->label()
                : null,
            'acting_college' => $this->resource->acting_college,
            'action' => $this->resource->action,
            'auditable_type' => $this->resource->auditable_type,
            'auditable_id' => $this->resource->auditable_id,
            'before_values' => $this->resource->before_values,
            'after_values' => $this->resource->after_values,
            // `ListAuditLogs` attaches names for the whole page; without them the
            // ids simply stay ids.
            'changes' => AuditChangeFormatter::changes(
                $this->resource->before_values,
                $this->resource->after_values,
                $this->resource->getAttribute('user_names') ?? [],
            ),
            'reason' => $this->resource->reason,
            'request_id' => $this->resource->request_id,
            'ip_address' => $this->resource->ip_address,
            'created_at' => $this->resource->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

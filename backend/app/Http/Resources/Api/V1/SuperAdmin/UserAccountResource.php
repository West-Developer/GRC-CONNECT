<?php

namespace App\Http\Resources\Api\V1\SuperAdmin;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read User $resource
 */
final class UserAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isManageable = $this->resource->role !== UserRole::SuperAdmin
            && $this->resource->role !== UserRole::QueueKiosk;

        return [
            'type' => 'user_account',
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'role' => $this->resource->role->value,
            'role_label' => $this->resource->role->label(),
            'college' => $this->resource->college?->value,
            'college_label' => $this->resource->college?->label(),
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status === UserStatus::Active ? 'Active' : 'Disabled',
            'pending_setup' => $this->resource->account_setup_completed_at === null,
            'account_setup_completed_at' => $this->resource->account_setup_completed_at?->toIso8601String(),
            'last_login_at' => $this->resource->last_login_at?->toIso8601String(),
            'created_at' => $this->resource->created_at->toIso8601String(),
            'manageable' => $isManageable,
            'active_session_count' => (int) ($this->resource->tokens_count ?? 0),
        ];
    }
}

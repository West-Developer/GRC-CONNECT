<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read User $resource
 */
final class FacultyMemberResource extends JsonResource
{
    /**
     * @return array{type: string, id: int, name: string, college: ?string, status: string, status_label: string, employment_type: ?string, employment_type_label: ?string, planning_unit_reference: ?int, is_assignable: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'faculty_member',
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'college' => $this->resource->college?->value,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status === UserStatus::Active
                ? 'Active'
                : 'Inactive',
            'employment_type' => $this->resource->employment_type?->value,
            'employment_type_label' => $this->resource->employment_type?->label(),
            'planning_unit_reference' => $this->resource->employment_type?->planningUnitReference(),
            'masters_degree' => $this->resource->masters_degree,
            'deactivation_reason' => $this->resource->deactivation_reason,
            'is_assignable' => $this->resource->status->value === 'active',
        ];
    }
}

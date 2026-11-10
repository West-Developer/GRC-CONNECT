<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Faculty account in a Program Chair's college, invited or already
 * active — the Chair's "who have I invited, and are they set up yet" view.
 *
 * @property-read User $resource
 */
final class FacultyInvitationResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     id: int,
     *     email: string,
     *     name: string,
     *     status: string,
     *     invitation_sent_at: ?string,
     *     activated_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'faculty_invitation',
            'id' => $this->resource->id,
            'email' => $this->resource->email,
            'name' => $this->resource->name,
            'status' => $this->status(),
            'invitation_sent_at' => $this->resource->account_setup_invitation_sent_at?->toIso8601String(),
            'activated_at' => $this->resource->account_setup_completed_at?->toIso8601String(),
        ];
    }

    private function status(): string
    {
        if ($this->resource->account_setup_completed_at !== null) {
            return 'activated';
        }
        if ($this->resource->account_setup_invitation_failed_at !== null) {
            return 'failed';
        }

        return 'pending';
    }
}

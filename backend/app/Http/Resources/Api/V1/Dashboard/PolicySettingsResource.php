<?php

namespace App\Http\Resources\Api\V1\Dashboard;

use App\Domain\Dashboard\PolicySettingsSummary;
use App\Domain\Dashboard\PolicyValueState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PolicySettingsSummary $resource
 */
final class PolicySettingsResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     values: list<array{
     *         key: string,
     *         label: string,
     *         current_value: ?string,
     *         status: string,
     *         status_label: string,
     *         description: string,
     *         prd_reference: ?string
     *     }>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'policy_settings_summary',
            'values' => array_map(
                fn (PolicyValueState $state): array => [
                    'key' => $state->key,
                    'label' => $state->label,
                    'current_value' => $state->currentValue,
                    'status' => $state->status->value,
                    'status_label' => $state->status->label(),
                    'description' => $state->description,
                    'prd_reference' => $state->prdReference,
                ],
                $this->resource->values,
            ),
        ];
    }
}

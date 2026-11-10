<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AttritionReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'attrition_report',
            'baseline_term' => $this->resource['baseline_term'],
            'comparison_term' => $this->resource['comparison_term'],
            'generated_at' => $this->resource['generated_at'],
            'summary' => $this->resource['summary'],
            'groups' => $this->resource['groups'],
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a CHED / NSTP list, exactly as the report builder produced it: the keys are the
 * column keys the preview's `report.columns` announces. Built from a plain array, not a model,
 * so there is nothing to lazy-load here.
 *
 * @property array<string, int|string> $resource
 */
final class ChedReportRowResource extends JsonResource
{
    /**
     * @return array<string, int|string>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}

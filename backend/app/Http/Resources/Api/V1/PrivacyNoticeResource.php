<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Privacy\PrivacyNotice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PrivacyNotice $resource
 */
final class PrivacyNoticeResource extends JsonResource
{
    /**
     * @return array{
     *     type: string,
     *     version: string,
     *     effective_date: string,
     *     controller: string,
     *     contact: array{name: ?string, email: string, phone: ?string}
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'privacy-notice',
            'version' => $this->resource->version(),
            'effective_date' => $this->resource->effectiveDate(),
            'controller' => $this->resource->controller(),
            'contact' => $this->resource->contact(),
        ];
    }
}

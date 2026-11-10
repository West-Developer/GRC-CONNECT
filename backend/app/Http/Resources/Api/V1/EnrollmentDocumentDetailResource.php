<?php

namespace App\Http\Resources\Api\V1;

use App\Models\EnrollmentDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EnrollmentDocument $resource
 */
final class EnrollmentDocumentDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'certificate_of_registration',
            'id' => $this->resource->id,
            'enrollment_id' => $this->resource->enrollment_id,
            'document_number' => $this->resource->certificateNumber(),
            'generated_at' => $this->resource->generated_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'content_hash' => $this->resource->content_hash,
            'snapshot' => $this->resource->snapshot,
        ];
    }
}

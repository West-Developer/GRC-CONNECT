<?php

namespace App\Http\Resources\Api\V1;

use App\Models\StudentTorDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read StudentTorDocument $resource
 */
final class TorDocumentResource extends JsonResource
{
    /**
     * Metadata only: the stored path is never exposed (the file is fetched
     * through the authorized `/tor-documents/{id}/file` endpoint). Needs
     * `student.user` eager-loaded.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     student_id: int,
     *     student_number: string,
     *     student_name: string,
     *     original_name: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     uploaded_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        $student = $this->resource->student;

        return [
            'type' => 'tor_document',
            'id' => $this->resource->id,
            'student_id' => $this->resource->student_id,
            'student_number' => $student->student_number,
            'student_name' => $student->user->name,
            'original_name' => $this->resource->original_name,
            'mime_type' => $this->resource->mime_type,
            'size_bytes' => $this->resource->size_bytes,
            'uploaded_at' => $this->resource->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Identity\UserRole;
use App\Models\EnrollmentDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EnrollmentDocument $resource
 */
final class EnrollmentDocumentResource extends JsonResource
{
    /**
     * Staff who have broad document access receive the student's display name
     * for the protected COR-record lookup. Students receive `null` and never
     * gain another student's identity through this list. No `storage_path` is
     * exposed — it stays null in this slice (see `EnrollmentDocument`'s own
     * docblock); FR-FIN-010's print/download is served from this structured
     * data, not a generated file.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     enrollment_id: int,
     *     student_number: string,
     *     student_name: string|null,
     *     document_type: string,
     *     document_type_label: string,
     *     document_number: string,
     *     generated_at: string
     * }
     */
    public function toArray(Request $request): array
    {
        $mayViewStudentName = in_array($request->user()?->role, [
            UserRole::AccountingStaff,
            UserRole::RegistrarHead,
            UserRole::RegistrarStaff,
        ], true);

        return [
            'type' => 'enrollment_document',
            'id' => $this->resource->id,
            'enrollment_id' => $this->resource->enrollment_id,
            'student_number' => $this->resource->enrollment->student->student_number,
            'student_name' => $mayViewStudentName ? $this->resource->enrollment->student->user->name : null,
            'document_type' => $this->resource->document_type->value,
            'document_type_label' => $this->resource->document_type->label(),
            'document_number' => $this->resource->certificateNumber(),
            'generated_at' => $this->resource->generated_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

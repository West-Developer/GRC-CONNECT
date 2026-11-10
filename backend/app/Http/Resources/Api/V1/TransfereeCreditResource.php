<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TransfereeCredit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read TransfereeCredit $resource
 */
final class TransfereeCreditResource extends JsonResource
{
    /**
     * Exact key set. `student_number` and `student_name` (never email) let the
     * Program Chair and Registrar Staff tell whose credit this is, matching
     * what those roles already see of a student elsewhere. The processor's
     * identity stays private to the audit log — never exposed here, matching
     * `WithdrawalRequestResource`. `requested_by_student` says whether the
     * Student asked for it themselves (as opposed to a Program Chair
     * recording it), without exposing who did.
     *
     * @return array{
     *     type: string,
     *     id: int,
     *     student_id: int,
     *     student_number: string,
     *     student_name: string,
     *     source_institution: string,
     *     source_subject_code: string,
     *     source_subject_title: string,
     *     source_grade: ?string,
     *     credited_units: float,
     *     source_school_year: ?string,
     *     source_semester: ?string,
     *     subject_id: ?int,
     *     subject_code: ?string,
     *     subject_title: ?string,
     *     requested_by_student: bool,
     *     status: string,
     *     status_label: string,
     *     endorsed_at: ?string,
     *     submitted_at: ?string,
     *     returned_at: ?string,
     *     return_reason: ?string,
     *     processed_at: ?string,
     *     created_at: ?string
     * }
     */
    public function toArray(Request $request): array
    {
        $student = $this->resource->student;

        return [
            'type' => 'transferee_credit',
            'id' => $this->resource->id,
            'student_id' => $this->resource->student_id,
            'student_number' => $student->student_number,
            'student_name' => $student->user->name,
            'source_institution' => $this->resource->source_institution,
            'source_subject_code' => $this->resource->source_subject_code,
            'source_subject_title' => $this->resource->source_subject_title,
            'source_grade' => $this->resource->source_grade,
            'credited_units' => $this->resource->credited_units,
            'source_school_year' => $this->resource->source_school_year,
            'source_semester' => $this->resource->source_semester,
            'subject_id' => $this->resource->subject_id,
            'subject_code' => $this->resource->subject?->code,
            'subject_title' => $this->resource->subject?->title,
            'requested_by_student' => $this->resource->requested_by !== null
                && $this->resource->requested_by === $student->user_id,
            'status' => $this->resource->status->value,
            'status_label' => $this->resource->status->label(),
            'endorsed_at' => $this->resource->endorsed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'submitted_at' => $this->resource->submitted_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'returned_at' => $this->resource->returned_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'return_reason' => $this->resource->return_reason,
            'processed_at' => $this->resource->processed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->resource->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

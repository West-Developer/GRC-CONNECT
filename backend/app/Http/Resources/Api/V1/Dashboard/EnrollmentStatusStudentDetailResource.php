<?php

namespace App\Http\Resources\Api\V1\Dashboard;

use App\Domain\Dashboard\EnrollmentStatusStudentDetail;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read EnrollmentStatusStudentDetail $resource
 */
final class EnrollmentStatusStudentDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detail = $this->resource;
        $enrollment = $detail->enrollment;

        return [
            'type' => 'enrollment_status_student_detail',
            'academic_term_id' => $detail->academicTermId,
            'student_profile_id' => $detail->studentProfileId,
            'student_number' => $detail->studentNumber,
            'student_name' => $detail->name,
            'program_code' => $detail->programCode,
            'program_name' => $detail->programName,
            'department' => $detail->department,
            'year_level' => $detail->yearLevel,
            'enrollment_category' => $detail->enrollmentCategory,
            'group' => $detail->group->value,
            'group_label' => $detail->group->label(),
            'section_code' => $detail->sectionCode,
            'enrollment' => $enrollment === null ? null : [
                'id' => $enrollment['id'],
                'status' => $enrollment['status'],
                'status_label' => $enrollment['status_label'],
                'total_units' => $enrollment['total_units'],
                'submitted_at' => $this->utc($enrollment['submitted_at']),
                'registrar_decided_at' => $this->utc($enrollment['registrar_decided_at']),
                'payment_confirmed_at' => $this->utc($enrollment['payment_confirmed_at']),
                'enrolled_at' => $this->utc($enrollment['enrolled_at']),
            ],
            'subjects' => $detail->subjects,
        ];
    }

    private function utc(?CarbonImmutable $value): ?string
    {
        return $value?->utc()->format('Y-m-d\TH:i:s\Z');
    }
}

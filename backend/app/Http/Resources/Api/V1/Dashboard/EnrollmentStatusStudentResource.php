<?php

namespace App\Http\Resources\Api\V1\Dashboard;

use App\Domain\Dashboard\EnrollmentStatusGroup;
use App\Domain\Enrollment\EnrollmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the audited student list (ADR 0024). The field set is fixed and
 * narrow on purpose: identity, program/year, section and enrollment status.
 * No contact data, grades or payment amounts.
 *
 * @property-read \stdClass $resource a row from `EnrollmentStatusPopulation::query()`
 */
final class EnrollmentStatusStudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $status = $row->enrollment_status === null
            ? null
            : EnrollmentStatus::tryFrom((string) $row->enrollment_status);
        $group = EnrollmentStatusGroup::forStatus($status);

        return [
            'type' => 'enrollment_status_student',
            'student_profile_id' => (int) $row->student_profile_id,
            'student_number' => $row->student_number,
            'student_name' => $row->student_name,
            'program_code' => $row->program_code,
            'program_name' => $row->program_name,
            'department' => $row->department,
            'year_level' => (int) $row->year_level,
            'section_code' => $row->section_code,
            'group' => $group->value,
            'group_label' => $group->label(),
            'enrollment_id' => $row->enrollment_id === null ? null : (int) $row->enrollment_id,
            'enrollment_status' => $status?->value,
            'enrollment_status_label' => $status?->label(),
            'submitted_at' => $this->utc($row->submitted_at),
            'enrolled_at' => $this->utc($row->enrolled_at),
        ];
    }

    private function utc(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}

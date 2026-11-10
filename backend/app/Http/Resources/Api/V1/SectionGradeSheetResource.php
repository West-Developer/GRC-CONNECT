<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AcademicGrade;
use App\Models\EnrollmentSubject;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SectionGradeSheetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Section $section */
        $section = $this->resource['section'];
        /** @var list<array{roster_entry: EnrollmentSubject, grade: ?AcademicGrade}> $rows */
        $rows = $this->resource['rows'];

        return [
            'type' => 'section_grade_sheet',
            'section' => SectionGradeSummaryResource::make($section),
            'rows' => array_map(function (array $row): array {
                $entry = $row['roster_entry'];
                $student = $entry->enrollment->student;
                $grade = $row['grade'];

                return [
                    'enrollment_subject_id' => $entry->id,
                    'student_id' => $student->id,
                    'student_number' => $student->student_number,
                    'student_name' => $student->user->name,
                    'grade_id' => $grade?->id,
                    'mark' => $grade?->mark?->value,
                    'mark_label' => $grade?->mark?->label(),
                    'remarks' => $grade?->remarks,
                    'status' => $grade?->status->value ?? 'not_recorded',
                    'status_label' => $grade?->status->label() ?? 'Not recorded',
                ];
            }, $rows),
        ];
    }
}

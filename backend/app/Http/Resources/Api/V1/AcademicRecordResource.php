<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Academic\AcademicRecord;
use App\Domain\Academic\AcademicRecordTerm;
use App\Models\AcademicGrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AcademicRecord $resource
 */
final class AcademicRecordResource extends JsonResource
{
    /**
     * Each entry in `terms` is shaped identically to `GradeSlipResource`
     * minus the student fields (hoisted to the top level here) — the
     * frontend's `GradeSlipDocument` renders either directly.
     *
     * @return array{
     *     type: string,
     *     student_id: int,
     *     student_number: string,
     *     program_code: string,
     *     program_name: string,
     *     year_level: int,
     *     enrollment_category: ?string,
     *     enrollment_category_label: ?string,
     *     terms: list<mixed>
     * }
     */
    public function toArray(Request $request): array
    {
        $student = $this->resource->student;

        return [
            'type' => 'academic_record',
            'student_id' => $student->id,
            'student_number' => $student->student_number,
            'student_name' => $student->user?->name,
            'program_code' => $student->program->code,
            'program_name' => $student->program->name,
            'year_level' => $student->year_level,
            'enrollment_category' => $student->enrollment_category,
            'enrollment_category_label' => $student->enrollment_category !== null
                ? ucfirst($student->enrollment_category)
                : null,
            'terms' => array_map(
                fn (AcademicRecordTerm $term): array => self::termToArray($term),
                $this->resource->terms,
            ),
        ];
    }

    /**
     * @return array{
     *     academic_term_id: int,
     *     school_year: string,
     *     semester: string,
     *     term_label: string,
     *     rows: list<mixed>,
     *     total_academic_units: float,
     *     gpa_units: float,
     *     gpa: ?string,
     *     excluded_from_gpa_count: int
     * }
     */
    private static function termToArray(AcademicRecordTerm $recordTerm): array
    {
        $term = $recordTerm->term;

        return [
            'academic_term_id' => $term->id,
            'school_year' => $term->school_year,
            'semester' => $term->semester,
            'term_label' => "{$term->school_year} · {$term->semester}",
            'rows' => array_map(
                fn (AcademicGrade $grade): array => GradeSlipResource::rowToArray($grade),
                $recordTerm->grades,
            ),
            'total_academic_units' => $recordTerm->totalAcademicUnits,
            'gpa_units' => $recordTerm->gpaUnits,
            'gpa' => $recordTerm->gpa,
            'excluded_from_gpa_count' => $recordTerm->excludedFromGpaCount,
        ];
    }
}

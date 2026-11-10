<?php

namespace App\Domain\Academic;

use App\Models\AcademicGrade;
use App\Models\AcademicTerm;

/**
 * One term's slice of a student's `AcademicRecord` — identical fields to
 * `GradeSlip` minus the (shared, hoisted-up) student, so the same totals
 * and the same `GradeSlipResource`-shaped row mapping serve both a
 * single-term grade slip and the multi-term academic record.
 */
final readonly class AcademicRecordTerm
{
    /**
     * @param  list<AcademicGrade>  $grades
     */
    public function __construct(
        public AcademicTerm $term,
        public array $grades,
        public float $totalAcademicUnits,
        public float $gpaUnits,
        public ?string $gpa,
        public int $excludedFromGpaCount,
    ) {}
}

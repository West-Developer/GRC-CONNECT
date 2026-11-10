<?php

namespace App\Domain\Academic;

use App\Models\AcademicGrade;
use App\Models\AcademicTerm;
use App\Models\StudentProfile;

/**
 * A single term's printable grade slip: every grade recorded for the
 * student in that term, plus the totals printed at its foot. `totalAcademicUnits`
 * is every row's units regardless of whether its mark counts toward the
 * GPA (matching the reference form's "TOTAL ACADEMIC UNITS," which sums
 * every row including a non-numeric-marked Leadership subject);
 * `gpaUnits`/`gpa` are the narrower GPA-only figures — see
 * `GradePointAverage`.
 */
final readonly class GradeSlip
{
    /**
     * @param  list<AcademicGrade>  $grades
     */
    public function __construct(
        public StudentProfile $student,
        public AcademicTerm $term,
        public array $grades,
        public float $totalAcademicUnits,
        public float $gpaUnits,
        public ?string $gpa,
        public int $excludedFromGpaCount,
    ) {}
}

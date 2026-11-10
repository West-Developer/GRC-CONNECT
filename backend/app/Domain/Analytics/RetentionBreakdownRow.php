<?php

namespace App\Domain\Analytics;

/**
 * One cell of the GradeStatus x EnrollmentStatus lifecycle crosstab computed
 * by BuildProgramChairAnalyticsSummary. Named "retention breakdown" rather
 * than a pass/fail "retention rate" deliberately — see that Action's
 * docblock: there is no PRD-authoritative passing-grade rule yet
 * (config('enrollment.grading.passing_grade') is explicitly not a signed-off
 * GRC decision), so this reports lifecycle-stage counts only.
 */
final readonly class RetentionBreakdownRow
{
    public function __construct(
        public string $gradeStatus,
        public string $enrollmentStatus,
        public int $count,
    ) {}
}

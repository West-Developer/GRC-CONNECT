<?php

namespace App\Domain\Dashboard;

/**
 * Every field here is a count of rows or a difference between two existing,
 * populated columns — arithmetic, not policy. Grouped by
 * App\Domain\Enrollment\EnrollmentStatus and App\Domain\Academic\GradeStatus,
 * both PRD-authoritative enums, so this value object never depends on a
 * provisional vocabulary. `payments.amount` is never summed here — it is
 * nullable by PRD §17 design; only payment *events* would ever be counted,
 * and this summary does not need them.
 */
final readonly class EnrollmentSummary
{
    /**
     * @param  array<string, int>  $statusCounts  EnrollmentStatus value => row count
     * @param  array<string, int>  $funnelCounts  lifecycle stage => rows that have reached it
     * @param  array<string, int>  $gradeStatusCounts  GradeStatus value => row count
     */
    public function __construct(
        public int $academicTermId,
        public array $statusCounts,
        public array $funnelCounts,
        public int $totalSections,
        public int $publishedSections,
        public int $totalCapacity,
        public int $totalEnrolledSeats,
        public array $gradeStatusCounts,
    ) {}
}

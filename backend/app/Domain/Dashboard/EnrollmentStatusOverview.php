<?php

namespace App\Domain\Dashboard;

/**
 * Counts of *students* (not enrollment rows) for one term, grouped by
 * `EnrollmentStatusGroup`, plus the per-step split of the students who have an
 * enrollment and the same groups per department (college). No student
 * identity is carried here; identities only appear through the separate,
 * audited student-list endpoint (ADR 0024).
 */
final readonly class EnrollmentStatusOverview
{
    /**
     * @param  array<string, int>  $groups  EnrollmentStatusGroup value => students
     * @param  array<string, int>  $steps  EnrollmentStatus value => students (draft through enrolled)
     * @param  list<array{department: ?string, label: string, total: int, groups: array<string, int>, steps?: array<string, int>}>  $departments
     */
    public function __construct(
        public int $academicTermId,
        public int $totalStudents,
        public array $groups,
        public array $steps,
        public array $departments,
    ) {}
}

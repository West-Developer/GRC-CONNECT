<?php

namespace App\Domain\Dashboard;

use Carbon\CarbonImmutable;

/**
 * The last level of the Enrollment Dashboard drill-down (ADR 0024): one
 * student's identity and their current enrollment for the term. Deliberately
 * narrow — no contact data, grades, or payment amounts — so it can be shown to
 * leadership roles that otherwise only see aggregates.
 */
final readonly class EnrollmentStatusStudentDetail
{
    /**
     * @param  ?array{
     *     id: int,
     *     status: string,
     *     status_label: string,
     *     total_units: float,
     *     submitted_at: ?CarbonImmutable,
     *     registrar_decided_at: ?CarbonImmutable,
     *     payment_confirmed_at: ?CarbonImmutable,
     *     enrolled_at: ?CarbonImmutable
     * }  $enrollment
     * @param  list<array{subject_code: string, subject_title: string, units: ?float, section_code: ?string, status: string, status_label: string}>  $subjects
     */
    public function __construct(
        public int $academicTermId,
        public int $studentProfileId,
        public string $studentNumber,
        public string $name,
        public ?string $programCode,
        public ?string $programName,
        public ?string $department,
        public int $yearLevel,
        public ?string $enrollmentCategory,
        public EnrollmentStatusGroup $group,
        public ?string $sectionCode,
        public ?array $enrollment,
        public array $subjects,
    ) {}
}

<?php

namespace App\Domain\Dashboard;

/**
 * The Executive Director's institution-wide counterpart to EnrollmentSummary
 * — same arithmetic, no term filter, plus a year-over-year breakdown that
 * `academic_terms.school_year` and its `UNIQUE(school_year, semester)`
 * constraint make structurally possible without any new institutional
 * decision (PRD §3.6).
 */
final readonly class InstitutionSummary
{
    /**
     * @param  array<string, int>  $statusCounts  EnrollmentStatus value => row count, all terms
     * @param  array<string, int>  $programCounts  program code => active-student count
     * @param  list<YearOverYearCount>  $yearOverYear  one entry per school_year, oldest first
     */
    public function __construct(
        public array $statusCounts,
        public int $totalPrograms,
        public int $activePrograms,
        public int $totalSections,
        public int $publishedSections,
        public array $programCounts,
        public array $yearOverYear,
    ) {}
}

<?php

namespace App\Support\Reports;

use App\Models\AcademicTerm;

/**
 * The texts the CHED forms print above the table: the school, where it is, and which term the list
 * is for. `termLabel` is the CHED Enrollment List's "1st Term, AY 2024-2025"; `semesterLabel` and
 * `schoolYear` fill the NSTP forms' "___ Semester : School Year : ___".
 */
final readonly class ChedReportHeader
{
    public function __construct(
        public string $schoolName,
        public string $schoolAddress,
        public string $termLabel,
        public string $semesterLabel,
        public string $schoolYear,
    ) {}

    public static function forTerm(AcademicTerm $term): self
    {
        return new self(
            schoolName: (string) config('ched.school_name'),
            schoolAddress: trim((string) config('ched.school_address')),
            termLabel: "{$term->semester} Term, AY {$term->school_year}",
            semesterLabel: "{$term->semester} Semester",
            schoolYear: $term->school_year,
        );
    }
}

<?php

namespace App\Domain\Academic;

use App\Models\AcademicGrade;
use App\Models\StudentProfile;

/**
 * A student's full curriculum, year 1 semester 1 through year 4 semester 2,
 * with a grade attempt where one exists and a blank entry where one
 * doesn't. `unplacedEntries` holds grades whose subject was never placed in
 * this curriculum (a transferee credit, a shifted-from program's leftover
 * grade) — surfaced explicitly rather than silently dropped, per the same
 * "never silently omit" doctrine `BuildEligibleSubjectPool` follows.
 */
final readonly class Prospectus
{
    /**
     * @param  list<ProspectusSemester>  $semesters
     * @param  list<AcademicGrade>  $unplacedEntries
     */
    public function __construct(
        public StudentProfile $student,
        public array $semesters,
        public array $unplacedEntries,
    ) {}
}

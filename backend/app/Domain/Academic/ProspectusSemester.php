<?php

namespace App\Domain\Academic;

use App\Domain\Curriculum\SemesterSlot;

/**
 * One (year level, semester) bucket of a prospectus — the unit a printed
 * prospectus groups subjects under.
 */
final readonly class ProspectusSemester
{
    /**
     * @param  list<ProspectusEntry>  $entries
     */
    public function __construct(
        public int $yearLevel,
        public SemesterSlot $semester,
        public array $entries,
    ) {}
}

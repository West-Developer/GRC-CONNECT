<?php

namespace App\Domain\Academic;

use App\Models\StudentProfile;

/**
 * A student's full grade history, every term they have a locked/submitted
 * grade in, latest term first — the read model behind the student Grades
 * screen's school-year/semester navigation. Unlike `Prospectus`, this does
 * not walk the curriculum; a term with zero grades simply never appears.
 */
final readonly class AcademicRecord
{
    /**
     * @param  list<AcademicRecordTerm>  $terms  latest term first
     */
    public function __construct(
        public StudentProfile $student,
        public array $terms,
    ) {}
}

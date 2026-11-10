<?php

namespace App\Domain\Academic;

use App\Domain\Curriculum\SemesterCoverage;
use App\Models\AcademicGrade;
use App\Models\CurriculumSubject;

/**
 * One curriculum placement's row on a student's prospectus: the subject as
 * planned, plus every grade attempt recorded against it (`attempts`) — zero
 * if never taken, more than one on a retake. A subject can have many grades
 * across terms but only ever one curriculum placement (`UNIQUE(curriculum_id,
 * subject_id)`), so this pairs the plan side (always present) with the
 * history side (sometimes empty) rather than trying to force them into one
 * shape.
 */
final readonly class ProspectusEntry
{
    /**
     * @param  list<AcademicGrade>  $attempts  Every grade recorded for this subject, most recent term first (the order `BuildStudentProspectus`'s query already produces).
     */
    public function __construct(
        public CurriculumSubject $placement,
        public bool $isCompletionOnly,
        public array $attempts,
    ) {}

    public function offeredEitherSemester(): bool
    {
        return SemesterCoverage::coversBoth($this->placement->semester);
    }

    /**
     * The attempt to display: the most recent LOCKED grade if one exists
     * (matching `BuildEligibleSubjectPool`'s own authoritative source), else
     * the most recent attempt of any status so a submitted-but-not-yet-locked
     * grade is still visible rather than the row looking untouched.
     */
    public function latestGrade(): ?AcademicGrade
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->status === GradeStatus::Locked) {
                return $attempt;
            }
        }

        return $this->attempts[0] ?? null;
    }
}

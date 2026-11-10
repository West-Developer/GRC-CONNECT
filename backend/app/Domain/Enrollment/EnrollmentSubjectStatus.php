<?php

namespace App\Domain\Enrollment;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * PRD §17 leaves the drop/seat-release rules open pending GRC approval, so the
 * `enrollment_subjects.status` column stays a plain string. Replace these
 * values with the confirmed vocabulary via a data migration before any
 * production-like deployment.
 */
enum EnrollmentSubjectStatus: string
{
    case Selected = 'selected';
    case Enrolled = 'enrolled';
    case Dropped = 'dropped';

    public function label(): string
    {
        return match ($this) {
            self::Selected => 'Selected',
            self::Enrolled => 'Enrolled',
            self::Dropped => 'Dropped',
        };
    }

    /**
     * Whether this row currently occupies a seat in its section. Used when
     * recomputing `sections.enrolled_count`; PRD §5.3 requires that a
     * withdrawal never decrement a section more than once.
     */
    public function occupiesSeat(): bool
    {
        return $this !== self::Dropped;
    }
}

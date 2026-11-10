<?php

namespace App\Domain\Scheduling;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * PRD §17 leaves institutional status vocabularies open pending GRC approval.
 * These values exist only so this slice has something concrete to authorize
 * and seed against; replace them with the confirmed vocabulary via a data
 * migration before any production-like deployment. The `sections.status`
 * column stays a plain string for exactly this reason.
 */
enum SectionStatus: string
{
    case Draft = 'draft';
    case Planned = 'planned';
    case Published = 'published';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Planned => 'Planned',
            self::Published => 'Published',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Only a published section may accept enrollment. Planned sections are
     * still under schedule review; closed and cancelled sections are not
     * enrollable.
     */
    public function acceptsEnrollment(): bool
    {
        return $this === self::Published;
    }

    /**
     * A section is exposed to students/professors once published (PRD
     * §5.1); closed sections stay visible as term history, matching
     * AcademicTermStatus's treatment of `closed`.
     */
    public function isVisibleToLearners(): bool
    {
        return match ($this) {
            self::Published, self::Closed => true,
            self::Draft, self::Planned, self::Cancelled => false,
        };
    }
}

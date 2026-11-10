<?php

namespace App\Domain\Enrollment;

enum EnrollmentAvailabilityReason: string
{
    case TermNotOpen = 'term_not_open';
    case TermClosed = 'term_closed';
    case BeforeWindow = 'before_window';
    case AfterWindow = 'after_window';
    case Open = 'open';

    /**
     * Pass the audience whose window produced this reason so an irregular
     * student is told "Enrollment for Irregular Students …" rather than the
     * year-level phrasing, which does not apply to them. Omitting it keeps
     * the older, audience-neutral wording.
     */
    public function message(?EnrollmentAudience $audience = null): string
    {
        $subject = $audience !== null ? $audience->label() : 'your year level';

        return match ($this) {
            self::TermNotOpen => 'Enrollment has not been opened for this term yet.',
            self::TermClosed => 'Enrollment for this term is closed.',
            self::BeforeWindow => "Enrollment for {$subject} has not opened yet.",
            self::AfterWindow => "Enrollment for {$subject} has closed.",
            self::Open => 'Enrollment is open.',
        };
    }
}

<?php

namespace App\Domain\Enrollment;

enum AddDropAvailabilityReason: string
{
    case TermNotOngoing = 'term_not_ongoing';
    case EnrollmentStillOpen = 'enrollment_still_open';
    case BeforeWindow = 'before_window';
    case DeadlineNotConfigured = 'deadline_not_configured';
    case DeadlinePassed = 'deadline_passed';
    case Open = 'open';

    public function message(): string
    {
        return match ($this) {
            self::TermNotOngoing => 'The add/drop window is only available while the term is ongoing.',
            self::EnrollmentStillOpen => 'The add/drop window opens once enrollment closes for this term.',
            self::BeforeWindow => 'The add/drop window has not opened yet.',
            self::DeadlineNotConfigured => 'The Registrar has not set an add/drop deadline for this term yet.',
            self::DeadlinePassed => 'The add/drop window for this term has closed.',
            self::Open => 'The add/drop window is open.',
        };
    }
}

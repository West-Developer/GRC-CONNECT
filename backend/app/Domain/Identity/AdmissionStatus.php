<?php

namespace App\Domain\Identity;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * PRD §10.1 requires an `admission_status` on the student profile but does not
 * enumerate its values, and PRD §17 leaves institutional vocabularies open
 * pending GRC approval. These values are the minimum needed to seed and
 * authorize against; replace them with the confirmed vocabulary via a data
 * migration before any production-like deployment.
 */
enum AdmissionStatus: string
{
    case Pending = 'pending';
    case Admitted = 'admitted';
    case Enrolled = 'enrolled';
    case Graduated = 'graduated';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Admitted => 'Admitted',
            self::Enrolled => 'Enrolled',
            self::Graduated => 'Graduated',
            self::Withdrawn => 'Withdrawn',
        };
    }
}

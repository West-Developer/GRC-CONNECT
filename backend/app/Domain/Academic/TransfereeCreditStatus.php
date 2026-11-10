<?php

namespace App\Domain\Academic;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * PRD §10.3 defers the transferee-credit source-institution fields to GRC
 * approval, and §17 leaves the surrounding vocabularies open. Replace these
 * values with the confirmed vocabulary via a data migration before any
 * production-like deployment.
 */
enum TransfereeCreditStatus: string
{
    /** The student's own saved list, not yet sent: private to them, grants and notifies nothing. */
    case Draft = 'draft';
    /** Sent by the Student (or recorded by the Program Chair); awaiting the Program Chair's mapping. */
    case Pending = 'pending';
    /** Mapped to a subject and endorsed by the Program Chair; awaiting the Registrar Head. */
    case Endorsed = 'endorsed';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending',
            self::Endorsed => 'Endorsed',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /** Still moving through the workflow: not yet approved or rejected. A draft is not in it yet. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Endorsed;
    }
}

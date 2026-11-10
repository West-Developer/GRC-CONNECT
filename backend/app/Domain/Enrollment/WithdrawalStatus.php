<?php

namespace App\Domain\Enrollment;

/**
 * PROVISIONAL VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
 *
 * PRD §17 leaves the withdrawal workflow and seat-release rules open pending
 * GRC approval. Replace these values with the confirmed vocabulary via a data
 * migration before any production-like deployment.
 */
enum WithdrawalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}

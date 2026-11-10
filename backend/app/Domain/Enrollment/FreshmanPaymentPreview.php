<?php

namespace App\Domain\Enrollment;

use App\Domain\Billing\FullPaymentQuote;
use App\Models\Enrollment;
use App\Models\StudentProfile;

/**
 * What confirming a first payment would do right now, without doing it (ADR 0043). For a
 * freshman: the block they would be submitted to (or none, when every block is full) and
 * the assessment that block would carry. For a transferee: the enrollment they chose and
 * submitted, which waits at the payment hold (or none, when they have not chosen yet), and
 * the assessment it would carry. Read-only and advisory — seats can still change before the
 * Cashier confirms, and the confirmation decides again.
 */
final readonly class FreshmanPaymentPreview
{
    public function __construct(
        public StudentProfile $profile,
        public ?string $ticketNumber,
        public string $minimumAmount,
        public bool $alreadyConfirmed,
        public ?EnrollmentBlock $block,
        public ?string $estimatedTotal,
        public ?Enrollment $enrollment = null,
        public ?FullPaymentQuote $fullPayment = null,
    ) {}
}

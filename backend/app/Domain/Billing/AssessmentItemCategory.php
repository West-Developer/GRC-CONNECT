<?php

namespace App\Domain\Billing;

/**
 * The kinds of line an assessment can carry. Treat this enum as
 * authoritative — see `App\Domain\Enrollment\EnrollmentStatus`'s identical
 * convention. Adding a category is a deliberate PRD §17 decision, not
 * something to bolt on silently: `ScholarshipDiscount` is the one such
 * decision so far (ADR 0025 — the Cashier's 100%/40%/20% scholarship, a
 * negative line worth that share of the tuition plus miscellaneous fees).
 * `FullPaymentDiscount` is the second such decision (ADR 0046 — the Cashier's
 * full-payment discount, a negative line written at payment confirmation).
 */
enum AssessmentItemCategory: string
{
    case Tuition = 'tuition';
    case Miscellaneous = 'miscellaneous';
    case ScholarshipDiscount = 'scholarship_discount';
    case FullPaymentDiscount = 'full_payment_discount';

    public function label(): string
    {
        return match ($this) {
            self::Tuition => 'Tuition',
            self::Miscellaneous => 'Miscellaneous',
            self::ScholarshipDiscount => 'Scholarship discount',
            self::FullPaymentDiscount => 'Full-payment discount',
        };
    }

    /** A negative line that reduces the assessment: never part of the base a discount is taken from. */
    public function isDiscount(): bool
    {
        return $this === self::ScholarshipDiscount || $this === self::FullPaymentDiscount;
    }
}

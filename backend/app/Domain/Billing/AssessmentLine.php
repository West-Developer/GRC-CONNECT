<?php

namespace App\Domain\Billing;

/**
 * One printed line of an assessment: tuition (with quantity/unit rate) or
 * a flat miscellaneous fee (`quantity`/`unitAmount` null — the fee is not
 * derived from a rate). Every amount is a decimal string, never a float —
 * see `AssessmentComputation`'s own docblock for why.
 */
final readonly class AssessmentLine
{
    public function __construct(
        public AssessmentItemCategory $category,
        public string $label,
        public ?string $quantity,
        public ?string $unitAmount,
        public string $amount,
    ) {}
}

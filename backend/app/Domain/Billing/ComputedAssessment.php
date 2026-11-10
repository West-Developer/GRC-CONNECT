<?php

namespace App\Domain\Billing;

/**
 * The result of `AssessmentComputation::compute()`: every line plus the
 * total those lines sum to. `totalAmount` is always exactly the sum of
 * `lines[*]->amount` — never recomputed independently — so the printed
 * total can never drift from its own breakdown.
 */
final readonly class ComputedAssessment
{
    /**
     * @param  list<AssessmentLine>  $lines
     */
    public function __construct(
        public array $lines,
        public string $totalAmount,
    ) {}
}

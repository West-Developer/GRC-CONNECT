<?php

namespace App\Actions\Billing;

use App\Domain\Billing\AssessmentItemCategory;
use App\Domain\Billing\FullPaymentDiscountPolicy;
use App\Domain\Billing\FullPaymentQuote;
use App\Domain\Billing\ScholarshipBase;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\PaymentDiscountSetting;

/**
 * What a full payment would collect right now (ADR 0046). The framework boundary of the
 * pure `FullPaymentDiscountPolicy`: it loads the saved setting when none is passed, reads
 * the school's calendar day, and adds the two reasons the pure class cannot know — a
 * scholarship already on the assessment, and a pay-first student with nothing to estimate
 * from. Writes nothing.
 */
final readonly class QuoteFullPayment
{
    /**
     * For an assessment that already exists (`$assessment->items` must be loaded).
     */
    public function forAssessment(Assessment $assessment, ?FullPaymentDiscountPolicy $policy = null): FullPaymentQuote
    {
        $items = $assessment->items;
        /** @var numeric-string $total */
        $total = (string) $assessment->total_amount;

        $hasScholarship = $items->contains(
            fn (AssessmentItem $item): bool => $item->category === AssessmentItemCategory::ScholarshipDiscount,
        );

        if ($hasScholarship) {
            return FullPaymentQuote::notApplied($total, FullPaymentQuote::REASON_SCHOLAR);
        }

        return $this->quote(ScholarshipBase::amount($items), $total, $policy);
    }

    /**
     * For a pay-first student, from the estimated total of the assessment they will get.
     *
     * @param  numeric-string|null  $estimatedTotal
     */
    public function forEstimate(?string $estimatedTotal, ?FullPaymentDiscountPolicy $policy = null): FullPaymentQuote
    {
        if ($estimatedTotal === null) {
            return FullPaymentQuote::unavailable();
        }

        /** @var numeric-string $estimatedTotal */
        return $this->quote($estimatedTotal, $estimatedTotal, $policy);
    }

    /**
     * @param  numeric-string  $base
     * @param  numeric-string  $total
     */
    private function quote(string $base, string $total, ?FullPaymentDiscountPolicy $policy): FullPaymentQuote
    {
        $policy ??= PaymentDiscountSetting::current()->toPolicy();
        $status = $policy->status(FullPaymentDiscountPolicy::today());

        if ($status !== FullPaymentDiscountPolicy::ACTIVE) {
            return FullPaymentQuote::notApplied($total, $status);
        }

        $discount = $policy->discountFor($base);

        return FullPaymentQuote::applied($base, $policy->percentage, $discount, bcsub($base, $discount, 2));
    }
}

<?php

namespace App\Actions\Billing;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Billing\AssessmentItemCategory;
use App\Domain\Billing\FullPaymentDiscountPolicy;
use App\Domain\Billing\ScholarshipBase;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Validation\ValidationException;

/**
 * Writes the full-payment discount (ADR 0046) as one negative assessment line and lowers the
 * assessment's total to the net. Both entry points must run inside the caller's database
 * transaction with the assessment row already locked; they lock the items themselves.
 *
 * The discount is only ever written when money is collected (never on an assessment that is
 * still being corrected), so it needs no recomputation and is never edited by hand.
 */
final readonly class ApplyFullPaymentDiscount
{
    public function __construct(
        private QuoteFullPayment $quoteFullPayment,
        private AuditRecorder $auditRecorder,
    ) {}

    /**
     * The Cashier's Pay in full for an enrollment that already has an assessment. Returns the
     * amount this payment collects: the discounted net when a discount applies, otherwise the
     * plain total.
     *
     * @param  numeric-string  $quotedAmount
     * @return numeric-string
     *
     * @throws ValidationException when `$quotedAmount` is no longer what a full payment collects
     */
    public function forCashierPayment(Assessment $assessment, string $quotedAmount, User $actor, AuditRequestContext $context): string
    {
        $assessment->setRelation('items', $assessment->items()->lockForUpdate()->get());
        $quote = $this->quoteFullPayment->forAssessment($assessment);

        // The quote's amounts are built from bcmath results, so they are numeric strings.
        /** @var numeric-string|null $netTotal */
        $netTotal = $quote->netTotal;
        /** @var numeric-string|null $percentage */
        $percentage = $quote->percentage;
        /** @var numeric-string|null $discountAmount */
        $discountAmount = $quote->discountAmount;

        if ($netTotal === null || bccomp(bcadd($quotedAmount, '0', 2), $netTotal, 2) !== 0) {
            throw ValidationException::withMessages([
                'quoted_amount' => 'The amount due changed. Review the new amount and confirm again.',
            ]);
        }

        if ($quote->applies() && $quote->baseTotal !== null && $percentage !== null && $discountAmount !== null) {
            $this->writeLine($assessment, $percentage, $discountAmount, $netTotal, 'cashier_payment', $actor, $context);
        }

        return $netTotal;
    }

    /**
     * A pay-first student's Registrar approval (ADR 0046): the percentage frozen when the Cashier
     * took their full payment is applied to the real assessment, but only if the credit they
     * hold covers the discounted net. Otherwise nothing is written and the payment simply
     * stays an installment. The saved window is deliberately not rechecked: the student was
     * promised this percentage when they paid.
     *
     * @param  numeric-string  $frozenPercentage
     * @param  numeric-string  $heldCredit
     */
    public function forHeldCredit(Assessment $assessment, string $frozenPercentage, string $heldCredit, User $actor, AuditRequestContext $context): bool
    {
        $items = $assessment->items()->lockForUpdate()->get();
        $assessment->setRelation('items', $items);

        if ($items->contains(fn (AssessmentItem $item): bool => $item->category->isDiscount())) {
            return false;
        }

        $base = ScholarshipBase::amount($items);
        $policy = new FullPaymentDiscountPolicy($frozenPercentage);
        $discount = $policy->discountFor($base);
        $net = bcsub($base, $discount, 2);

        if (bccomp($heldCredit, $net, 2) === -1) {
            return false;
        }

        $this->writeLine($assessment, $frozenPercentage, $discount, $net, 'held_credit_settlement', $actor, $context);

        return true;
    }

    /**
     * @param  numeric-string  $percentage
     * @param  numeric-string  $discount
     * @param  numeric-string  $net
     */
    private function writeLine(Assessment $assessment, string $percentage, string $discount, string $net, string $source, User $actor, AuditRequestContext $context): void
    {
        // A discount of nothing (a zero-fee assessment) leaves no line and no audit row.
        if (bccomp($discount, '0', 2) !== 1) {
            return;
        }

        $before = (string) $assessment->total_amount;

        $assessment->items()->create([
            'category' => AssessmentItemCategory::FullPaymentDiscount,
            'label' => 'Full-payment discount ('.FullPaymentDiscountPolicy::formatPercentage($percentage).'%)',
            'quantity' => bcadd($percentage, '0', 1),
            'unit_amount' => null,
            'amount' => bcmul($discount, '-1', 2),
        ]);
        $assessment->update(['total_amount' => $net]);

        $this->auditRecorder->record(
            $actor,
            AuditAction::ASSESSMENT_FULL_PAYMENT_DISCOUNT_APPLIED,
            AuditableType::ASSESSMENT,
            $assessment->id,
            ['total_amount' => $before],
            ['total_amount' => $net, 'percentage' => bcadd($percentage, '0', 1), 'discount_amount' => $discount, 'source' => $source],
            null,
            $context,
        );
    }
}

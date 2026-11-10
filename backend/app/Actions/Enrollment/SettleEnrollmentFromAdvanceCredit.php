<?php

namespace App\Actions\Enrollment;

use App\Actions\Billing\ApplyFullPaymentDiscount;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\StudentType;
use App\Models\AccountPayment;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Completes an approved enrollment from the payment a freshman already made
 * (ADR 0043). A freshman who paid while every section was full holds that money as
 * credit (`account_payments` with no enrollment); when they later enroll in a section
 * that opened and Registrar Staff approve it, this finishes the enrollment from that
 * credit — no queue ticket and no second payment.
 *
 * Only for a freshman whose first payment was confirmed and whose held credit reaches
 * the minimum first payment; anyone else, or too little credit, returns false and the
 * enrollment simply carries on to the Cashier as usual. The held rows are re-pointed
 * to the enrollment (so every balance and the COR see them there), and the one
 * `Payment` row (the idempotency anchor of `ConfirmPayment`) is created for PHP 0.00 so
 * nothing is counted twice. The Cashier who took the money is the one recorded as
 * confirming, not the Registrar who approved. Running it again finds no held credit.
 *
 * ADR 0046: a frozen full-payment percentage is applied to the assessment first, when the credit
 * covers the discounted net.
 */
final readonly class SettleEnrollmentFromAdvanceCredit
{
    public function __construct(
        private ConfirmPayment $confirmPayment,
        private ApplyFullPaymentDiscount $applyFullPaymentDiscount,
    ) {}

    public function execute(Enrollment $enrollment, User $fallbackActor, AuditRequestContext $context): bool
    {
        return DB::transaction(function () use ($enrollment, $fallbackActor, $context): bool {
            $student = StudentProfile::query()->whereKey($enrollment->student_id)->lockForUpdate()->firstOrFail();

            // A freshman or a transferee whose first payment the Cashier confirmed (ADR 0043, amended 2026-10-05).
            if (! $student->student_type instanceof StudentType || ! $student->student_type->isPayFirst() || $student->freshman_payment_confirmed_at === null) {
                return false;
            }

            $held = AccountPayment::query()
                ->where('student_id', $student->id)
                ->whereNull('enrollment_id')
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($held->isEmpty()) {
                return false;
            }

            $total = $held->reduce(fn (string $carry, AccountPayment $row): string => bcadd($carry, (string) $row->amount, 2), '0.00');

            if (bccomp($total, FreshmanPaymentRules::minimumAmount(), 2) === -1) {
                return false;
            }

            $cashier = User::query()->find($held->first()->received_by) ?? $fallbackActor;

            // ADR 0046: the percentage frozen when the Cashier took a full payment is applied to the
            // real assessment now, only if the held credit covers the discounted net. It is used
            // once either way (cleared below), so it can never apply to a later term.
            $frozen = $student->full_payment_discount_percentage;
            if ($frozen !== null) {
                $assessment = Assessment::query()->where('enrollment_id', $enrollment->id)->lockForUpdate()->first();

                if ($assessment instanceof Assessment) {
                    $this->applyFullPaymentDiscount->forHeldCredit($assessment, $frozen, $total, $fallbackActor, $context);
                }

                $student->forceFill(['full_payment_discount_percentage' => null])->save();
            }

            AccountPayment::query()->whereKey($held->modelKeys())->update(['enrollment_id' => $enrollment->id]);

            $this->confirmPayment->execute(
                $enrollment,
                ['amount' => '0.00', 'promissory_note_on_file' => false],
                $cashier,
                $context,
                freshmanFirstPayment: true,
            );

            return true;
        });
    }
}

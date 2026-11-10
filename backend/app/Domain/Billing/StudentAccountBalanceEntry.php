<?php

namespace App\Domain\Billing;

final readonly class StudentAccountBalanceEntry
{
    /**
     * @param  numeric-string  $assessmentAmount
     * @param  numeric-string  $confirmedPaymentAmount
     * @param  numeric-string  $accountPaymentAmount
     * @param  numeric-string  $outstandingBalance
     */
    public function __construct(
        public int $enrollmentId,
        public int $academicTermId,
        public string $academicTermLabel,
        /** @var numeric-string */
        public string $assessmentAmount,
        /** @var numeric-string */
        public string $confirmedPaymentAmount,
        /** @var numeric-string */
        public string $accountPaymentAmount,
        /** @var numeric-string */
        public string $outstandingBalance,
        public bool $promissoryNoteOnFile,
    ) {}
}

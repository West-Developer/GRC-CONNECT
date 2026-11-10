<?php

namespace App\Domain\Billing;

final readonly class StudentAccountBalance
{
    /**
     * @param  numeric-string  $totalAssessed
     * @param  numeric-string  $totalPaid
     * @param  numeric-string  $priorBalance
     * @param  numeric-string  $outstandingBalance
     * @param  numeric-string  $advancePaymentBalance
     * @param  list<StudentAccountBalanceEntry>  $entries
     * @param  list<array<string, mixed>>  $transactions
     */
    public function __construct(
        /** @var numeric-string */
        public string $totalAssessed,
        /** @var numeric-string */
        public string $totalPaid,
        /** @var numeric-string */
        public string $priorBalance,
        /** @var numeric-string */
        public string $outstandingBalance,
        /** @var numeric-string */
        public string $advancePaymentBalance,
        public bool $hasPromissoryNoteOnFile,
        public array $entries,
        public array $transactions = [],
    ) {}
}

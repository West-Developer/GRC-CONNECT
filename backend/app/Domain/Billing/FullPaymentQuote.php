<?php

namespace App\Domain\Billing;

/**
 * What paying in full would collect right now (ADR 0046). `netTotal` is always the amount a
 * full payment collects: the discounted amount when a discount applies, otherwise the plain
 * total. When no discount applies, `reason` says why (`off`, `scheduled`, `expired`,
 * `scholar` or `no_estimate`). Only `no_estimate` leaves every amount null: a pay-first
 * student with nothing to estimate from yet.
 */
final readonly class FullPaymentQuote
{
    public const REASON_SCHOLAR = 'scholar';

    public const REASON_NO_ESTIMATE = 'no_estimate';

    private function __construct(
        public ?string $baseTotal,
        public ?string $percentage,
        public ?string $discountAmount,
        public ?string $netTotal,
        public ?string $reason,
    ) {}

    /**
     * @param  numeric-string  $base
     * @param  numeric-string  $percentage
     * @param  numeric-string  $discountAmount
     * @param  numeric-string  $netTotal
     */
    public static function applied(string $base, string $percentage, string $discountAmount, string $netTotal): self
    {
        return new self($base, $percentage, $discountAmount, $netTotal, null);
    }

    /**
     * @param  numeric-string  $total
     */
    public static function notApplied(string $total, string $reason): self
    {
        return new self($total, null, null, $total, $reason);
    }

    public static function unavailable(): self
    {
        return new self(null, null, null, null, self::REASON_NO_ESTIMATE);
    }

    public function applies(): bool
    {
        return $this->reason === null;
    }
}

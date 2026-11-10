<?php

namespace App\Domain\Billing;

/**
 * The rules of the full-payment discount (ADR 0046), with no database and no framework
 * state: a percentage (0 to 100, one decimal) and an optional school-calendar window.
 * Dates are `Y-m-d` strings in the school's calendar (Asia/Manila), both ends inclusive,
 * compared as strings, which is exact for that format.
 */
final readonly class FullPaymentDiscountPolicy
{
    public const OFF = 'off';

    public const SCHEDULED = 'scheduled';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    private const TIMEZONE = 'Asia/Manila';

    /**
     * @param  numeric-string  $percentage  one decimal place, 0 to 100
     */
    public function __construct(
        public string $percentage,
        public ?string $validFrom = null,
        public ?string $validUntil = null,
    ) {}

    /** Today's date in the school's calendar, not the server's (UTC). */
    public static function today(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }

    /**
     * @return 'off'|'scheduled'|'active'|'expired'
     */
    public function status(string $day): string
    {
        if (bccomp($this->percentage, '0', 1) <= 0) {
            return self::OFF;
        }

        if ($this->validFrom !== null && $day < $this->validFrom) {
            return self::SCHEDULED;
        }

        if ($this->validUntil !== null && $day > $this->validUntil) {
            return self::EXPIRED;
        }

        return self::ACTIVE;
    }

    /**
     * The amount taken off `$base`, a positive two-decimal string rounded half-up to the
     * cent like every other assessment amount.
     *
     * @param  numeric-string  $base
     * @return numeric-string
     */
    public function discountFor(string $base): string
    {
        return bcadd(bcdiv(bcmul($base, $this->percentage, 6), '100', 6), '0.005', 2);
    }

    /**
     * "10" for 10.0 and "12.5" for 12.5: the percentage as it reads on a line label.
     *
     * @param  numeric-string  $percentage
     */
    public static function formatPercentage(string $percentage): string
    {
        $normalized = bcadd($percentage, '0', 1);

        return str_ends_with($normalized, '.0') ? substr($normalized, 0, -2) : $normalized;
    }
}

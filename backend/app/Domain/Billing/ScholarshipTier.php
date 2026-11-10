<?php

namespace App\Domain\Billing;

/**
 * The scholarship classifications the Cashier can assign at payment time
 * (ADR 0025): the share of a student's whole assessment (tuition plus
 * miscellaneous fees) that the scholarship covers. The backing value is the
 * percentage.
 */
enum ScholarshipTier: int
{
    case Full = 100;
    case Forty = 40;
    case Twenty = 20;

    public function label(): string
    {
        return match ($this) {
            self::Full => '100% Full Academic Scholarship',
            self::Forty => '40% Partial Scholarship',
            self::Twenty => '20% Partial Scholarship',
        };
    }

    /** The text of the negative assessment line, e.g. "Scholarship discount (40%)". */
    public function lineLabel(): string
    {
        return "Scholarship discount ({$this->value}%)";
    }

    /**
     * How the tier is stored on its assessment line: in `quantity`
     * (`decimal(6,1)`), so a later fee adjustment can recompute the discount
     * without a new column.
     */
    public function storedQuantity(): string
    {
        return number_format($this->value, 1, '.', '');
    }

    /**
     * The amount taken off `$base`, as a positive two-decimal string, rounded
     * half-up like every other assessment amount (`AssessmentComputation`).
     *
     * @param  numeric-string  $base
     * @return numeric-string
     */
    public function discountFor(string $base): string
    {
        return bcadd(bcdiv(bcmul($base, (string) $this->value, 4), '100', 4), '0.005', 2);
    }

    /** The stored quantity of a discount line back to its tier, or null if it is not one. */
    public static function fromStoredQuantity(?string $quantity): ?self
    {
        return $quantity === null ? null : self::tryFrom((int) round((float) $quantity));
    }
}

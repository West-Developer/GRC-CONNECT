<?php

namespace App\Actions\Enrollment;

use Illuminate\Validation\ValidationException;

/**
 * The payment rule for a freshman's first payment (ADR 0043): at least the configured
 * minimum — the same PHP 1,000 minimum every first enrollment payment already has — and
 * no promissory note. One place for the number, so the form request, the action and
 * the settlement from held credit all use the same value.
 */
final class FreshmanPaymentRules
{
    public const DEFAULT_MINIMUM = '1000.00';

    /**
     * @return numeric-string
     */
    public static function minimumAmount(): string
    {
        $configured = config('enrollment.freshman_payment.minimum_amount', self::DEFAULT_MINIMUM);

        return is_numeric($configured) ? bcadd((string) $configured, '0', 2) : self::DEFAULT_MINIMUM;
    }

    /**
     * A received amount as an exact two-decimal string, or a 422 on `amount`.
     *
     * @return numeric-string
     */
    public static function normalizeAmount(mixed $amount): string
    {
        if (! is_numeric($amount) || bccomp((string) $amount, '99999999.99', 2) === 1) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
        }

        $normalized = bcadd((string) $amount, '0', 2);

        if (bccomp($normalized, self::minimumAmount(), 2) === -1) {
            throw ValidationException::withMessages([
                'amount' => 'The first payment must be at least PHP '.number_format((float) self::minimumAmount(), 2).'.',
            ]);
        }

        return $normalized;
    }

    /**
     * A full payment's received amount (ADR 0046): the discounted estimate. This method does not
     * apply the first-payment minimum, but the request layer still does (ConfirmFreshmanPaymentRequest
     * keeps its `min:` rule on `amount` for every payment type), because the settlement from held
     * credit only fires once the credit reaches that minimum, so a full payment quoted below it would
     * be collected and then never settle. Here the amount must be a positive amount that fits the column.
     *
     * @return numeric-string
     */
    public static function normalizeFullAmount(mixed $amount): string
    {
        if (! is_numeric($amount) || bccomp((string) $amount, '99999999.99', 2) === 1 || bccomp((string) $amount, '0', 2) !== 1) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
        }

        return bcadd((string) $amount, '0', 2);
    }
}

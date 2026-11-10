<?php

namespace Tests\Unit\Actions;

use App\Actions\Enrollment\FreshmanPaymentRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A freshman's first payment is at least the minimum and, being compared as exact decimals,
 * never subject to floating-point error (ADR 0043).
 */
final class FreshmanPaymentRulesTest extends TestCase
{
    public function test_the_minimum_is_one_thousand_pesos_by_default(): void
    {
        self::assertSame('1000.00', FreshmanPaymentRules::minimumAmount());
    }

    public function test_the_minimum_can_be_set_in_config_without_a_code_change(): void
    {
        config(['enrollment.freshman_payment.minimum_amount' => '1500']);

        self::assertSame('1500.00', FreshmanPaymentRules::minimumAmount());
    }

    public function test_an_amount_at_or_above_the_minimum_is_normalized_to_two_decimals(): void
    {
        self::assertSame('1000.00', FreshmanPaymentRules::normalizeAmount('1000'));
        self::assertSame('1000.00', FreshmanPaymentRules::normalizeAmount(1000));
        self::assertSame('2500.50', FreshmanPaymentRules::normalizeAmount('2500.5'));
        self::assertSame('99999999.99', FreshmanPaymentRules::normalizeAmount('99999999.99'));
    }

    public function test_an_amount_below_the_minimum_is_refused_even_by_a_centavo(): void
    {
        $this->expectException(ValidationException::class);

        FreshmanPaymentRules::normalizeAmount('999.99');
    }

    public function test_a_missing_or_non_numeric_amount_is_refused(): void
    {
        foreach ([null, '', 'abc', '1,000'] as $bad) {
            try {
                FreshmanPaymentRules::normalizeAmount($bad);
                self::fail('Expected a validation error for '.var_export($bad, true));
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('amount', $exception->errors());
            }
        }
    }

    public function test_an_amount_beyond_the_column_capacity_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        FreshmanPaymentRules::normalizeAmount('100000000.00');
    }
}

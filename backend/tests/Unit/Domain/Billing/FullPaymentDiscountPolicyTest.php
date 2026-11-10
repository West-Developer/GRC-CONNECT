<?php

namespace Tests\Unit\Domain\Billing;

use App\Domain\Billing\FullPaymentDiscountPolicy;
use PHPUnit\Framework\TestCase;

final class FullPaymentDiscountPolicyTest extends TestCase
{
    public function test_it_is_active_with_no_dates(): void
    {
        self::assertSame('active', (new FullPaymentDiscountPolicy('10.0'))->status('2026-10-12'));
    }

    public function test_zero_percent_is_off_even_inside_the_window(): void
    {
        self::assertSame('off', (new FullPaymentDiscountPolicy('0.0', '2026-10-01', '2026-10-31'))->status('2026-10-12'));
    }

    public function test_both_window_ends_are_inclusive(): void
    {
        $policy = new FullPaymentDiscountPolicy('10.0', '2026-10-10', '2026-10-20');

        self::assertSame('scheduled', $policy->status('2026-10-09'));
        self::assertSame('active', $policy->status('2026-10-10'));
        self::assertSame('active', $policy->status('2026-10-20'));
        self::assertSame('expired', $policy->status('2026-10-21'));
    }

    public function test_a_single_date_leaves_the_other_side_open(): void
    {
        $from = new FullPaymentDiscountPolicy('10.0', '2026-10-10', null);
        self::assertSame('scheduled', $from->status('2026-10-09'));
        self::assertSame('active', $from->status('2030-01-01'));

        $until = new FullPaymentDiscountPolicy('10.0', null, '2026-10-20');
        self::assertSame('active', $until->status('2000-01-01'));
        self::assertSame('expired', $until->status('2026-10-21'));
    }

    public function test_the_discount_is_the_percentage_of_the_base_rounded_half_up(): void
    {
        $ten = new FullPaymentDiscountPolicy('10.0');

        self::assertSame('105.00', $ten->discountFor('1050.00'));
        self::assertSame('0.00', $ten->discountFor('0.00'));
        // 10% of 333.35 is 33.335, which rounds up to 33.34.
        self::assertSame('33.34', $ten->discountFor('333.35'));
        // 12.5% of 100.10 is 12.5125, which rounds down to 12.51.
        self::assertSame('12.51', (new FullPaymentDiscountPolicy('12.5'))->discountFor('100.10'));
        self::assertSame('1050.00', (new FullPaymentDiscountPolicy('100.0'))->discountFor('1050.00'));
    }

    public function test_the_percentage_reads_without_a_trailing_zero(): void
    {
        self::assertSame('10', FullPaymentDiscountPolicy::formatPercentage('10.0'));
        self::assertSame('12.5', FullPaymentDiscountPolicy::formatPercentage('12.5'));
        self::assertSame('100', FullPaymentDiscountPolicy::formatPercentage('100.0'));
    }
}

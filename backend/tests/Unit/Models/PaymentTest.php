<?php

namespace Tests\Unit\Models;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class PaymentTest extends TestCase
{
    public function test_confirmed_at_is_cast_to_carbon_immutable(): void
    {
        $payment = new Payment;
        $payment->forceFill([
            'enrollment_id' => 1,
            'confirmed_by' => 1,
            'confirmed_at' => '2026-08-05 09:00:00',
        ]);

        self::assertInstanceOf(CarbonImmutable::class, $payment->confirmed_at);
    }

    public function test_amount_deliberately_stays_a_raw_string_not_a_float(): void
    {
        $payment = new Payment;
        $payment->forceFill([
            'enrollment_id' => 1,
            'confirmed_by' => 1,
            'amount' => '1500.00',
            'confirmed_at' => '2026-08-05 09:00:00',
        ]);

        self::assertIsString($payment->amount);
        self::assertSame('1500.00', $payment->amount);
    }
}

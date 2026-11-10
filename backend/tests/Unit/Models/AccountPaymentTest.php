<?php

namespace Tests\Unit\Models;

use App\Models\AccountPayment;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class AccountPaymentTest extends TestCase
{
    public function test_received_at_is_immutable_and_amount_stays_an_exact_decimal_string(): void
    {
        $payment = new AccountPayment;
        $payment->forceFill([
            'student_id' => 1,
            'enrollment_id' => 2,
            'received_by' => 3,
            'amount' => '500.00',
            'received_at' => '2026-08-14 09:00:00',
        ]);

        self::assertInstanceOf(CarbonImmutable::class, $payment->received_at);
        self::assertIsString($payment->amount);
        self::assertSame('500.00', $payment->amount);
    }
}

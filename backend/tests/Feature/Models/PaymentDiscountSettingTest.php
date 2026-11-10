<?php

namespace Tests\Feature\Models;

use App\Models\PaymentDiscountSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentDiscountSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_read_creates_the_default_row_once(): void
    {
        self::assertSame(0, PaymentDiscountSetting::query()->count());

        $first = PaymentDiscountSetting::current();
        $second = PaymentDiscountSetting::current();

        self::assertSame('10.0', $first->percentage);
        self::assertNull($first->valid_from);
        self::assertNull($first->valid_until);
        self::assertSame($first->id, $second->id);
        self::assertSame(1, PaymentDiscountSetting::query()->count());
    }

    public function test_the_default_comes_from_the_fees_config(): void
    {
        config(['fees.full_payment_discount_percentage' => '12.5']);

        self::assertSame('12.5', PaymentDiscountSetting::current()->percentage);
    }

    public function test_a_saved_row_wins_over_the_config(): void
    {
        PaymentDiscountSetting::query()->create(['percentage' => '7.5', 'valid_from' => '2026-10-01', 'valid_until' => '2026-10-31']);
        config(['fees.full_payment_discount_percentage' => '12.5']);

        $setting = PaymentDiscountSetting::current();

        self::assertSame('7.5', $setting->percentage);
        self::assertSame('2026-10-01', $setting->valid_from?->toDateString());
        self::assertSame('2026-10-31', $setting->valid_until?->toDateString());
    }
}

<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\QueueServiceDate;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class QueueServiceDateTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_today_uses_manila_time_not_utc(): void
    {
        // 23:30 UTC on the 23rd is already 07:30 on the 24th in Manila (UTC+8).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 23:30:00', 'UTC'));

        self::assertSame('2026-08-24', QueueServiceDate::today());
        self::assertNotSame(CarbonImmutable::now('UTC')->toDateString(), QueueServiceDate::today());
    }

    public function test_timezone_defaults_to_asia_manila(): void
    {
        self::assertSame('Asia/Manila', QueueServiceDate::timezone());
    }
}

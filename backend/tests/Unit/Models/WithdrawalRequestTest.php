<?php

namespace Tests\Unit\Models;

use App\Domain\Enrollment\WithdrawalStatus;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class WithdrawalRequestTest extends TestCase
{
    public function test_status_and_processed_at_use_their_canonical_casts(): void
    {
        $request = new WithdrawalRequest;
        $request->forceFill([
            'enrollment_id' => 1,
            'reason' => 'Relocated to another institution.',
            'status' => 'approved',
            'processed_by' => 1,
            'processed_at' => '2026-08-05 09:00:00',
        ]);

        self::assertSame(WithdrawalStatus::Approved, $request->status);
        self::assertInstanceOf(CarbonImmutable::class, $request->processed_at);
    }
}

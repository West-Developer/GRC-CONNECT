<?php

namespace Tests\Unit\Domain\Enrollment;

use App\Domain\Enrollment\WithdrawalStatus;
use PHPUnit\Framework\TestCase;

final class WithdrawalStatusTest extends TestCase
{
    public function test_status_values_are_the_three_provisional_cases(): void
    {
        self::assertSame(
            ['pending', 'approved', 'rejected'],
            array_column(WithdrawalStatus::cases(), 'value'),
        );
    }

    public function test_labels_are_stable_and_human_readable(): void
    {
        self::assertSame('Pending', WithdrawalStatus::Pending->label());
        self::assertSame('Approved', WithdrawalStatus::Approved->label());
        self::assertSame('Rejected', WithdrawalStatus::Rejected->label());
    }
}

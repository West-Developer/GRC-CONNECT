<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\UserStatus;
use PHPUnit\Framework\TestCase;

final class UserStatusTest extends TestCase
{
    public function test_account_statuses_are_only_technical_access_states(): void
    {
        self::assertSame(
            ['active', 'disabled'],
            array_column(UserStatus::cases(), 'value'),
        );
    }
}

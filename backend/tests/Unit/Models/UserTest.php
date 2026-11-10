<?php

namespace Tests\Unit\Models;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function test_identity_attributes_use_the_canonical_enum_casts(): void
    {
        $user = new User;
        $user->forceFill([
            'role' => 'program_chair',
            'status' => 'active',
        ]);

        self::assertSame(UserRole::ProgramChair, $user->role);
        self::assertSame(UserStatus::Active, $user->status);
        self::assertContains('password', $user->getHidden());
    }
}

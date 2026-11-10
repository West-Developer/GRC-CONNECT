<?php

namespace Tests\Unit\Models;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserNamePartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_written_with_only_a_display_name_gets_its_structured_parts(): void
    {
        $user = User::create([
            'name' => 'Seed Student',
            'email' => 'name.parts@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        $this->assertSame('Seed', $user->fresh()->first_name);
        $this->assertSame('Student', $user->fresh()->last_name);
        $this->assertSame('Seed Student', $user->fresh()->name);
    }

    public function test_supplied_structured_parts_are_never_overwritten(): void
    {
        $user = User::create([
            'name' => 'Aurora S. Lopez',
            'first_name' => 'Aurora',
            'middle_initial' => 'S',
            'last_name' => 'Lopez',
            'email' => 'name.parts.kept@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        $this->assertSame('Aurora', $user->fresh()->first_name);
        $this->assertSame('S', $user->fresh()->middle_initial);
        $this->assertSame('Lopez', $user->fresh()->last_name);
    }
}

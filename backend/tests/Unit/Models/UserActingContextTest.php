<?php

namespace Tests\Unit\Models;

use App\Domain\Identity\ActingContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserActingContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_and_college_reflect_the_acting_context(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@grc.test',
            'password' => 'secret',
            'role' => UserRole::SuperAdmin,
            'status' => UserStatus::Active,
        ]);
        $admin->applyActingContext(ActingContext::fromRequest(UserRole::Dean, CollegeCode::Ccs));

        $this->assertSame(UserRole::Dean, $admin->role);
        $this->assertSame(CollegeCode::Ccs, $admin->college);
    }

    public function test_the_stored_row_and_raw_checks_are_unaffected(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@grc.test',
            'password' => 'secret',
            'role' => UserRole::SuperAdmin,
            'status' => UserStatus::Active,
        ]);
        $admin->applyActingContext(ActingContext::fromRequest(UserRole::Dean, CollegeCode::Ccs));

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertSame('super_admin', $admin->getRawOriginal('role'));
        $this->assertSame('super_admin', $admin->getAttributes()['role']);
        $this->assertFalse($admin->isDirty('role'));
    }

    public function test_to_array_does_not_crash_and_reflects_the_acting_context(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@grc.test',
            'password' => 'secret',
            'role' => UserRole::SuperAdmin,
            'status' => UserStatus::Active,
        ]);
        $admin->applyActingContext(ActingContext::fromRequest(UserRole::RegistrarHead, null));

        $array = $admin->toArray();
        $this->assertSame('registrar_head', $array['role']);
    }

    public function test_saving_while_acting_still_writes_the_real_role(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@grc.test',
            'password' => 'secret',
            'role' => UserRole::SuperAdmin,
            'status' => UserStatus::Active,
        ]);
        $admin->applyActingContext(ActingContext::fromRequest(UserRole::Dean, CollegeCode::Ccs));
        $admin->forceFill(['last_login_at' => now()])->save();

        $this->assertSame('super_admin', $admin->fresh()->getRawOriginal('role'));
    }

    public function test_a_non_super_admin_with_no_acting_context_is_unaffected(): void
    {
        $chair = User::create([
            'name' => 'Chair User',
            'email' => 'chair@grc.test',
            'password' => 'secret',
            'role' => UserRole::ProgramChair,
            'college' => CollegeCode::Ccs,
            'status' => UserStatus::Active,
        ]);
        $this->assertSame(UserRole::ProgramChair, $chair->role);
        $this->assertFalse($chair->isSuperAdmin());
    }
}

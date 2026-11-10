<?php

namespace Tests\Feature\Console;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ProvisionSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_super_admin_when_the_email_is_configured(): void
    {
        config(['super_admin.email' => 'owner@example.com']);

        $this->artisan('super-admin:provision', ['--name' => 'Westlie Casuncad'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'owner@example.com',
            'role' => UserRole::SuperAdmin->value,
            'status' => UserStatus::Active->value,
        ]);

        $admin = User::where('email', 'owner@example.com')->first();
        $this->assertNotNull($admin->account_setup_completed_at);
    }

    public function test_it_is_idempotent(): void
    {
        config(['super_admin.email' => 'owner@example.com']);
        $this->artisan('super-admin:provision', ['--name' => 'Westlie Casuncad']);
        $firstId = User::where('email', 'owner@example.com')->value('id');

        $this->artisan('super-admin:provision', ['--name' => 'Westlie Casuncad'])
            ->assertExitCode(0);

        $this->assertSame($firstId, User::where('email', 'owner@example.com')->value('id'));
        $this->assertSame(1, User::where('email', 'owner@example.com')->count());
    }

    public function test_it_refuses_to_hijack_an_existing_non_super_admin_email(): void
    {
        config(['super_admin.email' => 'chair.seed@grc.test']);
        User::create([
            'name' => 'X',
            'email' => 'chair.seed@grc.test',
            'password' => 'secret12#Aa',
            'role' => UserRole::ProgramChair,
            'status' => UserStatus::Active,
        ]);

        $this->artisan('super-admin:provision', ['--name' => 'X'])->assertExitCode(1);
    }

    public function test_it_refuses_without_a_configured_email(): void
    {
        config(['super_admin.email' => null]);
        $this->artisan('super-admin:provision', ['--name' => 'X'])->assertExitCode(1);
    }

    public function test_deactivate_disables_and_revokes_tokens(): void
    {
        config(['super_admin.email' => 'owner@example.com']);
        $this->artisan('super-admin:provision', ['--name' => 'Westlie Casuncad']);
        $admin = User::where('email', 'owner@example.com')->first();
        $admin->createToken('test');

        $this->assertSame(1, $admin->tokens()->count());

        $this->artisan('super-admin:provision', ['--deactivate' => true])->assertExitCode(0);

        $this->assertSame(UserStatus::Disabled, $admin->refresh()->status);
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_both_actions_are_audited(): void
    {
        config(['super_admin.email' => 'owner@example.com']);
        $this->artisan('super-admin:provision', ['--name' => 'Westlie Casuncad'])->assertExitCode(0);

        $admin = User::where('email', 'owner@example.com')->first();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::SUPER_ADMIN_PROVISIONED,
            'actor_user_id' => $admin->id,
            'auditable_id' => $admin->id,
        ]);

        $this->artisan('super-admin:provision', ['--deactivate' => true])->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::SUPER_ADMIN_DEACTIVATED,
            'actor_user_id' => $admin->id,
            'auditable_id' => $admin->id,
        ]);
    }

    public function test_it_can_provision_with_a_specified_password(): void
    {
        config(['super_admin.email' => 'owner@example.com']);

        $this->artisan('super-admin:provision', [
            '--name' => 'Westlie Casuncad',
            '--password' => 'Temporary@2026!',
        ])->assertExitCode(0);

        $admin = User::where('email', 'owner@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('Temporary@2026!', $admin->password));
        $this->assertNotNull($admin->last_otp_verified_at);
    }

    public function test_it_can_update_password_on_re_provision(): void
    {
        config(['super_admin.email' => 'owner@example.com']);

        $this->artisan('super-admin:provision', [
            '--name' => 'Westlie Casuncad',
            '--password' => 'Initial@2026!',
        ])->assertExitCode(0);

        $this->artisan('super-admin:provision', [
            '--name' => 'Westlie Casuncad',
            '--password' => 'Updated@2026!',
        ])->assertExitCode(0);

        $admin = User::where('email', 'owner@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('Updated@2026!', $admin->password));
    }
}

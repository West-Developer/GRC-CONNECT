<?php

namespace Tests\Feature\Api\V1\SuperAdmin;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ActingContextEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function makeUser(UserRole $role, string $email): User
    {
        return User::create([
            'name' => 'User '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function tokenFor(User $user): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_a_super_admin_can_switch_to_an_office(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.switch@grc.test');
        $token = $this->tokenFor($admin);

        $response = $this->withToken($token)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => 'dean',
                'college' => 'ccs',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.role', 'dean')
            ->assertJsonPath('data.college', 'ccs')
            ->assertJsonPath('data.acting_context.role', 'dean')
            ->assertJsonPath('data.acting_context.college', 'ccs');
    }

    public function test_dean_without_a_college_is_rejected(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.no-col@grc.test');
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => 'dean',
            ])
            ->assertUnprocessable();
    }

    public static function nonSwitchableRolesProvider(): array
    {
        return [
            ['student'],
            ['faculty'],
            ['queue_kiosk'],
            ['super_admin'],
        ];
    }

    #[DataProvider('nonSwitchableRolesProvider')]
    public function test_non_switchable_roles_are_rejected(string $role): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, "admin.invalid-{$role}@grc.test");
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => $role,
            ])
            ->assertUnprocessable();
    }

    public function test_a_non_super_admin_is_forbidden(): void
    {
        $dean = $this->makeUser(UserRole::Dean, 'dean.try-switch@grc.test');
        $token = $this->tokenFor($dean);

        $this->withToken($token)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => 'dean',
                'college' => 'ccs',
            ])
            ->assertForbidden();
    }

    public function test_switching_then_calling_a_registrar_head_only_route_works(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.act-reg@grc.test');
        $token = $this->tokenFor($admin);

        // Before switching, super_admin is not allowed on registrar_head only route
        $this->withToken($token)
            ->postJson('/api/v1/academic-terms', [
                'school_year' => '2026-2027',
                'semester' => 1,
            ])
            ->assertForbidden();

        // Switch to registrar_head
        $this->withToken($token)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => 'registrar_head',
            ])
            ->assertOk();

        auth()->forgetGuards();

        // Now postJson('/api/v1/academic-terms') is allowed past the role check
        $response = $this->withToken($token)
            ->postJson('/api/v1/academic-terms', [
                'name' => '1st Sem 2026-2027',
                'school_year' => '2026-2027',
                'semester' => 1,
                'term_type' => 'regular',
                'start_date' => '2026-08-01',
                'end_date' => '2026-12-31',
            ]);

        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_exit_clears_the_context(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.exit@grc.test');
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => 'registrar_head',
            ])
            ->assertOk();

        auth()->forgetGuards();

        $response = $this->withToken($token)
            ->deleteJson('/api/v1/super-admin/acting-context');

        $response->assertOk()
            ->assertJsonPath('data.role', 'super_admin')
            ->assertJsonPath('data.acting_context', null);
    }

    public function test_context_is_per_token_not_per_user(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.pertoken@grc.test');
        $token1 = $this->tokenFor($admin);
        $token2 = $this->tokenFor($admin);

        $this->withToken($token1)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => 'registrar_head',
            ])
            ->assertOk();

        auth()->forgetGuards();

        $response2 = $this->withToken($token2)->getJson('/api/v1/auth/me');
        $response2->assertOk()
            ->assertJsonPath('data.role', 'super_admin')
            ->assertJsonPath('data.acting_context', null);
    }

    public function test_the_action_is_audited(): void
    {
        $admin = $this->makeUser(UserRole::SuperAdmin, 'admin.audit@grc.test');
        $token = $this->tokenFor($admin);

        $this->withToken($token)
            ->putJson('/api/v1/super-admin/acting-context', [
                'role' => 'dean',
                'college' => 'ccs',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::SUPER_ADMIN_ACTING_CONTEXT_CHANGED,
            'actor_user_id' => $admin->id,
        ]);
    }
}

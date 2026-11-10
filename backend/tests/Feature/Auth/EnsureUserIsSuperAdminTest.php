<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Http\Middleware\ApplySuperAdminActingContext;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class EnsureUserIsSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', EnsureUserIsActive::class, ApplySuperAdminActingContext::class, EnsureUserIsSuperAdmin::class])
            ->get('/api/v1/_test/super-admin-gated', fn () => response()->json(['ok' => true]));
    }

    private function tokenFor(UserRole $role, string $email): string
    {
        User::create([
            'name' => 'Test '.$role->value,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_super_admin_passes(): void
    {
        $token = $this->tokenFor(UserRole::SuperAdmin, 'admin.test@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/_test/super-admin-gated')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        $token = $this->tokenFor(UserRole::Dean, 'dean.test@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/_test/super-admin-gated')
            ->assertForbidden();
    }

    public function test_super_admin_still_passes_when_acting_as_another_role(): void
    {
        $admin = User::create([
            'name' => 'Test Super Admin Acting',
            'email' => 'admin.acting@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::SuperAdmin,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);

        $tokenStr = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'admin.acting@grc.test',
            'password' => self::PASSWORD,
        ])->json('data.token');

        $tokenModel = $admin->tokens()->first();
        $tokenModel->update([
            'acting_role' => UserRole::Dean->value,
            'acting_college' => CollegeCode::Ccs->value,
        ]);

        $this->withToken($tokenStr)
            ->getJson('/api/v1/_test/super-admin-gated')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_unauthenticated_is_unauthorized(): void
    {
        $this->getJson('/api/v1/_test/super-admin-gated')
            ->assertUnauthorized();
    }
}

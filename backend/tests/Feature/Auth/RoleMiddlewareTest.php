<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Covers the `role` middleware alias (App\Http\Middleware\EnsureUserHasRole)
 * in isolation, via a test-only route, before any production route consumes
 * it. Record-level authorization is covered separately by Policy tests.
 */
final class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', EnsureUserIsActive::class, 'role:program_chair,dean'])
            ->get('/api/v1/_test/role-gated', fn () => response()->json(['ok' => true]));
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

    public function test_a_listed_role_passes(): void
    {
        $token = $this->tokenFor(UserRole::Dean, 'dean.role-test@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/_test/role-gated')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_an_unlisted_role_is_forbidden(): void
    {
        $token = $this->tokenFor(UserRole::Student, 'student.role-test@grc.test');

        $this->withToken($token)
            ->getJson('/api/v1/_test/role-gated')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_an_anonymous_request_is_unauthenticated(): void
    {
        $this->getJson('/api/v1/_test/role-gated')
            ->assertUnauthorized();
    }
}

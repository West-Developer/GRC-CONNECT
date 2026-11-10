<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers GET /api/v1/auth/me and POST /api/v1/auth/logout.
 */
final class SessionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function seedUser(UserStatus $status = UserStatus::Active): User
    {
        return User::create([
            'name' => 'Seed Registrar Head',
            'email' => 'registrar-head.seed@grc.test',
            'password' => self::PASSWORD,
            'role' => UserRole::RegistrarHead,
            'status' => $status,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function loginToken(): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'registrar-head.seed@grc.test',
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_me_returns_the_authenticated_identity(): void
    {
        $this->seedUser();
        $token = $this->loginToken();

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.type', 'user');
        $response->assertJsonPath('data.email', 'registrar-head.seed@grc.test');
        $response->assertJsonPath('data.role', 'registrar_head');
        $response->assertJsonPath('data.role_label', 'Registrar Head');
        $response->assertJsonPath('data.status', 'active');
    }

    public function test_me_never_exposes_the_password_hash(): void
    {
        $this->seedUser();
        $token = $this->loginToken();

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertJsonMissingPath('data.password');
        $this->assertStringNotContainsString('$2y$', $response->getContent() ?: '');
    }

    public function test_me_returns_no_token(): void
    {
        $this->seedUser();
        $token = $this->loginToken();

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertJsonMissingPath('data.token');
    }

    public function test_me_requires_a_bearer_token(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_me_rejects_a_fabricated_token(): void
    {
        $this->seedUser();

        $response = $this->withToken('1|totallymadeupvalue')->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $this->seedUser();
        $firstToken = $this->loginToken();
        $secondToken = $this->loginToken();

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withToken($firstToken)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->withToken($secondToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_revoked_token_can_no_longer_authenticate(): void
    {
        $this->seedUser();
        $token = $this->loginToken();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // A real HTTP request boots a fresh application, but the test client
        // reuses one container across calls and the auth guard caches the
        // user it already resolved. Forgetting the guards reproduces the
        // production behaviour of authenticating this token from scratch.
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_logout_requires_a_bearer_token(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_a_token_belonging_to_a_disabled_account_is_refused(): void
    {
        $user = $this->seedUser();
        $token = $this->loginToken();

        $user->update(['status' => UserStatus::Disabled]);

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\CollegeCode;
use App\Http\Middleware\ApplySuperAdminActingContext;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApplySuperAdminActingContextTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', EnsureUserIsActive::class, ApplySuperAdminActingContext::class])
            ->get('/api/v1/_test/acting-inspect', function (Request $request) {
                /** @var \App\Models\User $user */
                $user = $request->user();

                return response()->json([
                    'is_super_admin' => $user->isSuperAdmin(),
                    'role' => $user->role->value,
                    'college' => $user->college?->value,
                    'acting_role' => $user->actingContext()?->role->value,
                    'acting_college' => $user->actingContext()?->college?->value,
                ]);
            });
    }

    private function createUser(UserRole $role, string $email): User
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

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->json('data.token');
    }

    public function test_non_super_admin_has_no_acting_context_applied(): void
    {
        $this->createUser(UserRole::Dean, 'dean.act@grc.test');
        $token = $this->login('dean.act@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/_test/acting-inspect');

        $response->assertOk()
            ->assertJson([
                'is_super_admin' => false,
                'role' => 'dean',
                'college' => null,
                'acting_role' => null,
                'acting_college' => null,
            ]);
    }

    public function test_super_admin_without_acting_context_remains_super_admin(): void
    {
        $this->createUser(UserRole::SuperAdmin, 'admin.raw@grc.test');
        $token = $this->login('admin.raw@grc.test');

        $response = $this->withToken($token)->getJson('/api/v1/_test/acting-inspect');

        $response->assertOk()
            ->assertJson([
                'is_super_admin' => true,
                'role' => 'super_admin',
                'college' => null,
                'acting_role' => null,
                'acting_college' => null,
            ]);
    }

    public function test_super_admin_with_token_context_has_context_applied(): void
    {
        $admin = $this->createUser(UserRole::SuperAdmin, 'admin.active@grc.test');
        $token = $this->login('admin.active@grc.test');

        $tokenModel = $admin->tokens()->first();
        $tokenModel->update([
            'acting_role' => UserRole::Dean->value,
            'acting_college' => CollegeCode::Ccs->value,
        ]);

        $response = $this->withToken($token)->getJson('/api/v1/_test/acting-inspect');

        $response->assertOk()
            ->assertJson([
                'is_super_admin' => true,
                'role' => 'dean',
                'college' => 'ccs',
                'acting_role' => 'dean',
                'acting_college' => 'ccs',
            ]);
    }

    public function test_concurrent_tokens_for_same_super_admin_have_independent_context(): void
    {
        $admin = $this->createUser(UserRole::SuperAdmin, 'admin.multi@grc.test');
        $token1 = $this->login('admin.multi@grc.test');
        $token2 = $this->login('admin.multi@grc.test');

        $tokens = $admin->tokens()->orderBy('id')->get();
        $this->assertCount(2, $tokens);

        // Update only token 1
        $tokens[0]->update([
            'acting_role' => UserRole::Dean->value,
            'acting_college' => CollegeCode::Ccs->value,
        ]);

        auth()->forgetGuards();

        $response1 = $this->withToken($token1)->getJson('/api/v1/_test/acting-inspect');
        $response1->assertOk()
            ->assertJson([
                'role' => 'dean',
                'college' => 'ccs',
            ]);

        auth()->forgetGuards();

        $response2 = $this->withToken($token2)->getJson('/api/v1/_test/acting-inspect');
        $response2->assertOk()
            ->assertJson([
                'role' => 'super_admin',
                'college' => null,
            ]);
    }

    public function test_a_mismatched_header_is_rejected_with_409(): void
    {
        $admin = $this->createUser(UserRole::SuperAdmin, 'admin.stale@grc.test');
        $token = $this->login('admin.stale@grc.test');

        $admin->tokens()->first()->update([
            'acting_role' => UserRole::Dean->value,
            'acting_college' => CollegeCode::Ccs->value,
        ]);

        // This tab still believes it is in the Console ("none"), but the
        // token itself was switched to Dean by another tab.
        $response = $this->withToken($token)
            ->withHeaders(['X-Acting-Context' => 'none'])
            ->getJson('/api/v1/_test/acting-inspect');

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'ACTING_CONTEXT_CHANGED');
    }

    public function test_a_matching_header_is_accepted(): void
    {
        $admin = $this->createUser(UserRole::SuperAdmin, 'admin.synced@grc.test');
        $token = $this->login('admin.synced@grc.test');

        $admin->tokens()->first()->update([
            'acting_role' => UserRole::RegistrarHead->value,
        ]);

        $response = $this->withToken($token)
            ->withHeaders(['X-Acting-Context' => 'registrar_head'])
            ->getJson('/api/v1/_test/acting-inspect');

        $response->assertOk()->assertJsonPath('role', 'registrar_head');
    }

    public function test_a_missing_header_is_never_blocked(): void
    {
        $admin = $this->createUser(UserRole::SuperAdmin, 'admin.nosync@grc.test');
        $token = $this->login('admin.nosync@grc.test');

        $admin->tokens()->first()->update([
            'acting_role' => UserRole::Dean->value,
            'acting_college' => CollegeCode::Ccs->value,
        ]);

        // No X-Acting-Context header at all (e.g. the very first request
        // after sign-in, before the frontend store has synced) — absence
        // alone must never be treated as a mismatch.
        $response = $this->withToken($token)->getJson('/api/v1/_test/acting-inspect');

        $response->assertOk()->assertJsonPath('role', 'dean');
    }

    public function test_the_acting_context_endpoints_are_exempt_from_the_guard(): void
    {
        $admin = $this->createUser(UserRole::SuperAdmin, 'admin.exempt@grc.test');
        $token = $this->login('admin.exempt@grc.test');

        $admin->tokens()->first()->update([
            'acting_role' => UserRole::Dean->value,
            'acting_college' => CollegeCode::Ccs->value,
        ]);

        // A stale "none" header must still be able to resync via /auth/me,
        // and to exit/switch via the acting-context endpoints themselves.
        $this->withToken($token)
            ->withHeaders(['X-Acting-Context' => 'none'])
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->withToken($token)
            ->withHeaders(['X-Acting-Context' => 'none'])
            ->deleteJson('/api/v1/super-admin/acting-context')
            ->assertOk();
    }
}

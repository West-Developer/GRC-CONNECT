<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Public, always the identical generic response — no account enumeration.
 */
final class ForgotPasswordEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const RESPONSE_MESSAGE = 'If an account exists for this email, a password reset code has been sent.';

    private function makeUser(
        string $email = 'active.student@grc.test',
        UserStatus $status = UserStatus::Active,
        UserRole $role = UserRole::Student,
    ): User {
        return User::create([
            'name' => 'Active Student',
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'status' => $status,
        ]);
    }

    public function test_a_known_active_account_receives_a_reset_email_and_the_generic_response(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.type', 'forgot-password');
        $response->assertJsonPath('data.status', 'sent');
        $response->assertJsonPath('data.message', self::RESPONSE_MESSAGE);

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->hasTo($user->email));
        $this->assertDatabaseCount('password_reset_codes', 1);
        self::assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::PASSWORD_RESET_CODE_SENT)->count(),
        );
    }

    public function test_an_unknown_email_gets_the_identical_generic_response_and_sends_nothing(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@grc.test']);

        $response->assertOk();
        $response->assertJsonPath('data.message', self::RESPONSE_MESSAGE);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_codes', 0);
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_a_disabled_account_gets_the_identical_generic_response_and_sends_nothing(): void
    {
        Mail::fake();
        $this->makeUser(status: UserStatus::Disabled);

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'active.student@grc.test']);

        $response->assertOk();
        $response->assertJsonPath('data.message', self::RESPONSE_MESSAGE);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_codes', 0);
    }

    public function test_a_queue_kiosk_email_gets_the_identical_generic_response_and_sends_nothing(): void
    {
        Mail::fake();
        $this->makeUser(role: UserRole::QueueKiosk);

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'active.student@grc.test']);

        $response->assertOk();
        $response->assertJsonPath('data.message', self::RESPONSE_MESSAGE);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_codes', 0);
    }

    public function test_a_malformed_email_fails_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'not-an-email']);

        $response->assertUnprocessable();
        self::assertArrayHasKey('email', $response->json('error.errors'));
    }

    public function test_requests_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@grc.test'])
                ->assertOk();
        }

        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@grc.test']);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    }
}

<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGoogleIdTokenVerifier;
use Tests\TestCase;

final class GoogleLoginEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_TOKEN = 'valid-google-id-token';

    private function bindVerifierFor(string $email): void
    {
        $this->app->instance(
            GoogleIdTokenVerifier::class,
            FakeGoogleIdTokenVerifier::forEmail(self::VALID_TOKEN, $email),
        );
    }

    private function makeUser(
        string $email,
        UserStatus $status = UserStatus::Active,
        UserRole $role = UserRole::Student,
    ): User {
        return User::create([
            'name' => 'Google Test User',
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'status' => $status,
        ]);
    }

    public function test_a_matched_active_account_receives_a_full_session_and_stamps_otp_verification(): void
    {
        $user = $this->makeUser('google.match@grc.test');
        $this->bindVerifierFor('google.match@grc.test');

        $response = $this->postJson('/api/v1/auth/google', ['credential' => self::VALID_TOKEN]);

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.type', 'auth-session');
        $response->assertJsonPath('data.user.email', 'google.match@grc.test');
        $this->assertNotEmpty($response->json('data.token'));
        self::assertNotNull($user->fresh()?->last_otp_verified_at);
    }

    public function test_the_matched_email_is_compared_case_insensitively(): void
    {
        $this->makeUser('case.google@grc.test');
        $this->bindVerifierFor('CASE.GOOGLE@grc.test');

        $this->postJson('/api/v1/auth/google', ['credential' => self::VALID_TOKEN])->assertOk();
    }

    public function test_an_unmatched_email_gets_the_contact_admission_message_and_creates_no_account(): void
    {
        $this->bindVerifierFor('nobody.google@grc.test');

        $response = $this->postJson('/api/v1/auth/google', ['credential' => self::VALID_TOKEN]);

        $response->assertNotFound();
        self::assertStringContainsString('contact Admission', $response->json('error.message'));
        $this->assertDatabaseMissing('users', ['email' => 'nobody.google@grc.test']);
    }

    public function test_a_disabled_account_is_indistinguishable_from_no_match(): void
    {
        $this->makeUser('disabled.google@grc.test', UserStatus::Disabled);
        $this->bindVerifierFor('disabled.google@grc.test');
        $disabled = $this->postJson('/api/v1/auth/google', ['credential' => self::VALID_TOKEN]);

        $this->bindVerifierFor('truly.unknown.google@grc.test');
        $unmatched = $this->postJson('/api/v1/auth/google', ['credential' => self::VALID_TOKEN]);

        $disabled->assertNotFound();
        self::assertSame(
            $unmatched->json('error.message'),
            $disabled->json('error.message'),
            'A disabled account must not be distinguishable from a missing one.',
        );
    }

    public function test_a_queue_kiosk_account_is_indistinguishable_from_no_match(): void
    {
        $this->makeUser('kiosk.google@grc.test', UserStatus::Active, UserRole::QueueKiosk);
        $this->bindVerifierFor('kiosk.google@grc.test');
        $kiosk = $this->postJson('/api/v1/auth/google', ['credential' => self::VALID_TOKEN]);

        $this->bindVerifierFor('truly.unknown.google@grc.test');
        $unmatched = $this->postJson('/api/v1/auth/google', ['credential' => self::VALID_TOKEN]);

        $kiosk->assertNotFound();
        self::assertSame($unmatched->json('error.message'), $kiosk->json('error.message'));
    }

    public function test_a_garbage_credential_is_rejected(): void
    {
        $this->bindVerifierFor('whoever@grc.test');

        $response = $this->postJson('/api/v1/auth/google', ['credential' => 'not-a-real-token']);

        $response->assertUnauthorized();
    }

    public function test_missing_credential_fails_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/google', []);

        $response->assertUnprocessable();
    }

    public function test_a_successful_login_audits_login_succeeded_with_the_google_method(): void
    {
        $user = $this->makeUser('audit.google@grc.test');
        $this->bindVerifierFor('audit.google@grc.test');

        $this->postJson('/api/v1/auth/google', ['credential' => self::VALID_TOKEN])->assertOk();

        $log = AuditLog::query()->where('action', AuditAction::LOGIN_SUCCEEDED)->sole();
        self::assertSame($user->id, $log->actor_user_id);
        self::assertSame('google', $log->after_values['method'] ?? null);
    }
}

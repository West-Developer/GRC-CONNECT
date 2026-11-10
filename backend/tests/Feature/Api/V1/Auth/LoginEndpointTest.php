<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Mail\LoginOtpMail;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class LoginEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login|student.seed@grc.test|127.0.0.1');
    }

    /**
     * `$lastOtpVerifiedAt` defaults to "just now" so every test in this file
     * that predates the login-OTP slice keeps exercising the no-challenge
     * password-login contract unchanged. Pass `null` to exercise the
     * OTP-required path instead (see `LoginOtpEndpointTest`-style cases below).
     */
    private function seedUser(
        UserStatus $status = UserStatus::Active,
        string $email = 'student.seed@grc.test',
        UserRole $role = UserRole::Student,
        ?CarbonImmutable $lastOtpVerifiedAt = new CarbonImmutable,
    ): User {
        return User::create([
            'name' => 'Seed Student',
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'status' => $status,
            'last_otp_verified_at' => $lastOtpVerifiedAt,
        ]);
    }

    public function test_valid_credentials_return_the_exact_auth_envelope(): void
    {
        $this->seedUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ]);

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');

        $response->assertJsonStructure([
            'data' => [
                'type',
                'token',
                'token_type',
                'expires_at',
                'user' => ['id', 'name', 'email', 'role', 'role_label', 'college', 'status'],
            ],
        ]);

        $response->assertJsonPath('data.type', 'auth-session');
        $response->assertJsonPath('data.token_type', 'Bearer');
        $response->assertJsonPath('data.user.email', 'student.seed@grc.test');
        $response->assertJsonPath('data.user.role', 'student');
        $response->assertJsonPath('data.user.role_label', 'Student');
        $response->assertJsonPath('data.user.status', 'active');

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_the_response_never_exposes_the_password_hash(): void
    {
        $this->seedUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ]);

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertStringNotContainsString('$2y$', $response->getContent() ?: '');
    }

    public function test_successful_login_records_the_last_login_timestamp(): void
    {
        $user = $this->seedUser();
        $this->assertNull($user->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertNotNull($user->fresh()?->last_login_at);
    }

    public function test_successful_login_persists_exactly_one_token(): void
    {
        $this->seedUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_issues_the_kiosk_claim_ability_only_to_queue_kiosk_accounts(): void
    {
        $this->seedUser(email: 'queue-kiosk.seed@grc.test', role: UserRole::QueueKiosk);
        $this->seedUser(email: 'student.ability.seed@grc.test');

        $kioskPlainTextToken = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'queue-kiosk.seed@grc.test',
            'password' => self::PASSWORD,
        ])->json('data.token');

        $studentPlainTextToken = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'student.ability.seed@grc.test',
            'password' => self::PASSWORD,
        ])->json('data.token');

        $kioskToken = PersonalAccessToken::findToken($kioskPlainTextToken);
        $studentToken = PersonalAccessToken::findToken($studentPlainTextToken);

        self::assertNotNull($kioskToken);
        self::assertNotNull($studentToken);
        self::assertSame(['queue-kiosk:claim'], $kioskToken->abilities);
        self::assertSame(['*'], $studentToken->abilities);
    }

    public function test_the_stored_token_is_hashed_not_plain_text(): void
    {
        $this->seedUser();

        $plainTextToken = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ])->json('data.token');

        $stored = DB::table('personal_access_tokens')->value('token');

        $this->assertNotSame($plainTextToken, $stored);
        $this->assertSame(hash('sha256', explode('|', (string) $plainTextToken)[1]), $stored);
    }

    public function test_email_is_matched_case_insensitively_and_ignores_surrounding_space(): void
    {
        $this->seedUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => '  STUDENT.SEED@GRC.TEST  ',
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    public function test_a_wrong_password_is_rejected_with_the_generic_failure(): void
    {
        $this->seedUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_an_unknown_email_returns_the_identical_generic_failure(): void
    {
        $this->seedUser();

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => 'wrong-password',
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@grc.test',
            'password' => self::PASSWORD,
        ]);

        $unknownEmail->assertStatus(401);
        $this->assertSame(
            $wrongPassword->json('error.message'),
            $unknownEmail->json('error.message'),
            'Login must not reveal whether an account exists.',
        );
    }

    public function test_a_disabled_account_returns_the_identical_generic_failure(): void
    {
        $this->seedUser(UserStatus::Disabled);

        $disabled = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@grc.test',
            'password' => self::PASSWORD,
        ]);

        $disabled->assertStatus(401);
        $this->assertSame(
            $unknownEmail->json('error.message'),
            $disabled->json('error.message'),
            'A disabled account must not be distinguishable from a missing one.',
        );
    }

    public function test_a_disabled_account_is_issued_no_token(): void
    {
        $this->seedUser(UserStatus::Disabled);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ])->assertStatus(401);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_failed_login_does_not_record_a_login_timestamp(): void
    {
        $user = $this->seedUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->assertNull($user->fresh()?->last_login_at);
    }

    public function test_missing_fields_fail_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['errors' => ['email', 'password']]]);
    }

    public function test_a_malformed_email_fails_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => self::PASSWORD,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        $this->seedUser();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'student.seed@grc.test',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('error.code', 'THROTTLED');
        $response->assertHeader('Retry-After');
        // ApiExceptionRenderer substitutes one fixed, already non-enumerating
        // message for every 429 in the app — confirm it stays that way here.
        $this->assertSame(
            'Too many requests. Please retry later.',
            $response->json('error.message'),
        );
    }

    public function test_a_wrong_password_against_a_known_account_records_a_login_failed_audit_row(): void
    {
        $user = $this->seedUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $log = AuditLog::query()->where('action', AuditAction::LOGIN_FAILED)->sole();
        self::assertSame($user->id, $log->actor_user_id);
        self::assertSame($user->id, $log->auditable_id);
    }

    public function test_an_unknown_email_records_no_login_failed_audit_row(): void
    {
        $this->seedUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@grc.test',
            'password' => self::PASSWORD,
        ])->assertStatus(401);

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_a_disabled_account_records_a_login_failed_audit_row_too(): void
    {
        $user = $this->seedUser(UserStatus::Disabled);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ])->assertStatus(401);

        $log = AuditLog::query()->where('action', AuditAction::LOGIN_FAILED)->sole();
        self::assertSame($user->id, $log->auditable_id);
    }

    public function test_a_successful_login_records_a_login_succeeded_audit_row_with_the_password_method(): void
    {
        $user = $this->seedUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ])->assertOk();

        $log = AuditLog::query()->where('action', AuditAction::LOGIN_SUCCEEDED)->sole();
        self::assertSame($user->id, $log->actor_user_id);
        self::assertSame($user->id, $log->auditable_id);
        self::assertSame('password', $log->after_values['method'] ?? null);
    }

    public function test_an_account_that_has_never_verified_an_otp_gets_a_challenge_instead_of_a_token(): void
    {
        Mail::fake();
        $this->seedUser(lastOtpVerifiedAt: null);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ]);

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.type', 'login-otp-challenge');
        $response->assertJsonPath('data.otp_required', true);
        $response->assertJsonPath('data.email', 'student.seed@grc.test');
        $this->assertNotEmpty($response->json('data.challenge_token'));
        $this->assertArrayNotHasKey('token', $response->json('data'));

        Mail::assertSent(LoginOtpMail::class);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_stale_otp_verification_re_requires_a_fresh_challenge(): void
    {
        Mail::fake();
        $this->seedUser(lastOtpVerifiedAt: CarbonImmutable::now()->subMinutes(31));

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ]);

        $response->assertJsonPath('data.type', 'login-otp-challenge');
    }

    public function test_a_recent_otp_verification_skips_the_challenge(): void
    {
        Mail::fake();
        $this->seedUser(lastOtpVerifiedAt: CarbonImmutable::now()->subMinutes(29));

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ]);

        $response->assertJsonPath('data.type', 'auth-session');
        Mail::assertNothingSent();
    }

    public function test_a_queue_kiosk_account_never_gets_a_challenge_regardless_of_otp_history(): void
    {
        Mail::fake();
        $this->seedUser(
            email: 'queue-kiosk.otp.seed@grc.test',
            role: UserRole::QueueKiosk,
            lastOtpVerifiedAt: null,
        );

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'queue-kiosk.otp.seed@grc.test',
            'password' => self::PASSWORD,
        ]);

        $response->assertJsonPath('data.type', 'auth-session');
        Mail::assertNothingSent();
    }

    /**
     * Owner-confirmed transitional gap: an account that already existed
     * before the OTP feature's rollout cutoff is permanently exempt, even
     * with a null `last_otp_verified_at` — the same allowance already
     * applied to the password-complexity rule, extended to login OTP.
     */
    public function test_an_account_created_before_the_enforcement_cutoff_is_grandfathered_in(): void
    {
        Mail::fake();
        config(['auth.login_otp.enforced_after' => now()->addMinute()->toDateTimeString()]);
        $this->seedUser(lastOtpVerifiedAt: null);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ]);

        $response->assertJsonPath('data.type', 'auth-session');
        Mail::assertNothingSent();
    }

    public function test_an_account_created_after_the_enforcement_cutoff_still_requires_otp(): void
    {
        Mail::fake();
        config(['auth.login_otp.enforced_after' => now()->subMinute()->toDateTimeString()]);
        $this->seedUser(lastOtpVerifiedAt: null);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'student.seed@grc.test',
            'password' => self::PASSWORD,
        ]);

        $response->assertJsonPath('data.type', 'login-otp-challenge');
    }
}

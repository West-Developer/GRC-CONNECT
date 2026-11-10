<?php

namespace Tests\Feature\Actions\Auth;

use App\Actions\Auth\VerifyLoginOtp;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\LoginOtpChallenges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class VerifyLoginOtpTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'OTP Verify Test User',
            'email' => 'verify.otp.test@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => null,
        ]);
    }

    private function context(): AuditRequestContext
    {
        return new AuditRequestContext('verify-otp-test', '127.0.0.1');
    }

    public function test_the_right_code_issues_a_session_consumes_the_challenge_and_stamps_verification(): void
    {
        $user = $this->makeUser();
        $issued = app(LoginOtpChallenges::class)->issue($user);

        $result = app(VerifyLoginOtp::class)->handle($issued['token'], $issued['code'], $this->context());

        self::assertSame($user->id, $result['user']->id);
        self::assertNotNull($result['user']->last_otp_verified_at);
        self::assertNotNull(PersonalAccessToken::findToken($result['token']->plainTextToken));
        $this->assertDatabaseCount('login_otp_challenges', 0);

        $log = AuditLog::query()->where('action', AuditAction::LOGIN_SUCCEEDED)->sole();
        self::assertSame('password_otp', $log->after_values['method'] ?? null);
    }

    public function test_a_wrong_code_is_rejected_and_durably_recorded_against_the_account(): void
    {
        $user = $this->makeUser();
        $issued = app(LoginOtpChallenges::class)->issue($user);
        $wrong = $issued['code'] === '123456' ? '654321' : '123456';

        try {
            app(VerifyLoginOtp::class)->handle($issued['token'], $wrong, $this->context());
            $this->fail('Expected a ValidationException for a wrong code.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'This verification code is invalid or expired.',
                $exception->errors()['code'][0] ?? null,
            );
        }

        $this->assertDatabaseHas('login_otp_challenges', ['user_id' => $user->id, 'attempts' => 1]);
        $log = AuditLog::query()->where('action', AuditAction::LOGIN_OTP_FAILED)->sole();
        self::assertSame($user->id, $log->actor_user_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_unknown_token_is_rejected_without_touching_any_account(): void
    {
        try {
            app(VerifyLoginOtp::class)->handle('not-a-real-token', '123456', $this->context());
            $this->fail('Expected a ValidationException for an unknown token.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'This verification code is invalid or expired.',
                $exception->errors()['code'][0] ?? null,
            );
        }

        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }
}

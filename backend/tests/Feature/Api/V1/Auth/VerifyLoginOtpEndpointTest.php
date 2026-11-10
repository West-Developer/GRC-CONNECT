<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\LoginOtpChallenge;
use App\Models\User;
use App\Support\Auth\LoginOtpChallenges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VerifyLoginOtpEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'OTP Endpoint Test User',
            'email' => 'otp.endpoint.test@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => null,
        ]);
    }

    public function test_the_right_code_returns_a_full_session_and_persists_exactly_one_token(): void
    {
        $user = $this->makeUser();
        $issued = app(LoginOtpChallenges::class)->issue($user);

        $response = $this->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_token' => $issued['token'],
            'code' => $issued['code'],
        ]);

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.type', 'auth-session');
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotNull($user->fresh()?->last_otp_verified_at);
    }

    public function test_a_wrong_code_is_rejected_and_counts_against_the_attempt_limit(): void
    {
        $user = $this->makeUser();
        $issued = app(LoginOtpChallenges::class)->issue($user);
        $wrong = $issued['code'] === '123456' ? '654321' : '123456';

        $response = $this->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_token' => $issued['token'],
            'code' => $wrong,
        ]);

        $response->assertUnprocessable();
        self::assertSame(
            'This verification code is invalid or expired.',
            $response->json('error.errors.code.0'),
        );
        $this->assertDatabaseHas('login_otp_challenges', ['user_id' => $user->id, 'attempts' => 1]);
    }

    public function test_a_challenge_stops_working_after_the_attempt_limit_even_if_the_last_guess_is_right(): void
    {
        config(['auth.login_otp.max_attempts' => 3]);
        $user = $this->makeUser();
        $issued = app(LoginOtpChallenges::class)->issue($user);
        $wrong = $issued['code'] === '123456' ? '654321' : '123456';

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login/verify-otp', [
                'challenge_token' => $issued['token'],
                'code' => $wrong,
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_token' => $issued['token'],
            'code' => $issued['code'],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_expired_challenge_is_rejected(): void
    {
        $user = $this->makeUser();
        $issued = app(LoginOtpChallenges::class)->issue($user);
        LoginOtpChallenge::query()->where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_token' => $issued['token'],
            'code' => $issued['code'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'This verification code is invalid or expired.');
    }

    public function test_a_garbage_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_token' => 'not-a-real-token',
            'code' => '123456',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'This verification code is invalid or expired.');
    }

    public function test_an_already_consumed_token_cannot_be_used_a_second_time(): void
    {
        $user = $this->makeUser();
        $issued = app(LoginOtpChallenges::class)->issue($user);

        $this->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_token' => $issued['token'],
            'code' => $issued['code'],
        ])->assertOk();

        // The first, correct use already consumed the challenge — a second
        // request with the identical token+code (the shape of a naive replay
        // or a losing side of a race) must not be able to mint a second token.
        $this->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_token' => $issued['token'],
            'code' => $issued['code'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'This verification code is invalid or expired.');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_missing_fields_fail_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/login/verify-otp', []);

        $response->assertUnprocessable();
        $response->assertJsonStructure(['error' => ['errors' => ['challenge_token', 'code']]]);
    }
}

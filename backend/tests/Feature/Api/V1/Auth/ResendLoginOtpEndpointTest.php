<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Mail\LoginOtpMail;
use App\Models\LoginOtpChallenge;
use App\Models\User;
use App\Support\Auth\LoginOtpChallenges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ResendLoginOtpEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'OTP Resend Test User',
            'email' => 'otp.resend.test@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => null,
        ]);
    }

    public function test_a_live_challenge_is_rotated_and_the_old_token_stops_working(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $original = app(LoginOtpChallenges::class)->issue($user);

        $response = $this->postJson('/api/v1/auth/login/resend-otp', [
            'challenge_token' => $original['token'],
        ]);

        $response->assertOk();
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('data.type', 'login-otp-challenge');
        $response->assertJsonPath('data.email', $user->email);
        $rotatedToken = $response->json('data.challenge_token');
        $this->assertNotSame($original['token'], $rotatedToken);
        $this->assertDatabaseCount('login_otp_challenges', 1);
        Mail::assertSent(LoginOtpMail::class);

        // The old token must no longer verify anything.
        $this->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_token' => $original['token'],
            'code' => $original['code'],
        ])->assertUnprocessable();
    }

    public function test_a_missing_or_expired_challenge_token_is_rejected(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/login/resend-otp', [
            'challenge_token' => 'not-a-real-token',
        ]);

        $response->assertUnprocessable();
        self::assertSame(
            'Your sign-in session has expired. Please sign in again.',
            $response->json('error.errors.challenge_token.0'),
        );
        Mail::assertNothingSent();
    }

    public function test_an_expired_challenge_token_is_rejected(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $issued = app(LoginOtpChallenges::class)->issue($user);
        LoginOtpChallenge::query()->where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/login/resend-otp', ['challenge_token' => $issued['token']])
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.errors.challenge_token.0',
                'Your sign-in session has expired. Please sign in again.',
            );
        Mail::assertNothingSent();
    }

    public function test_missing_fields_fail_validation(): void
    {
        $response = $this->postJson('/api/v1/auth/login/resend-otp', []);

        $response->assertUnprocessable();
        $response->assertJsonStructure(['error' => ['errors' => ['challenge_token']]]);
    }
}

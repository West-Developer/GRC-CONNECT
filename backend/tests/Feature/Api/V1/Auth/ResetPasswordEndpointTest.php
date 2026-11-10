<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Support\Auth\PasswordResetCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ResetPasswordEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'New-Secure-Password1!';

    private function makeUser(
        UserStatus $status = UserStatus::Active,
        UserRole $role = UserRole::Student,
    ): User {
        return User::create([
            'name' => 'Reset Test User',
            'email' => 'reset.test@grc.test',
            'password' => 'old-correct-horse-battery-staple',
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function payload(User $user, string $code, string $password = self::NEW_PASSWORD): array
    {
        return [
            'email' => $user->email,
            'code' => $code,
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }

    public function test_a_valid_code_and_strong_password_resets_it_and_revokes_every_existing_token(): void
    {
        $user = $this->makeUser();
        // Two pre-existing sessions from before the reset.
        $user->createToken('device-a');
        $user->createToken('device-b');
        $code = app(PasswordResetCodes::class)->issue($user);

        $response = $this->postJson('/api/v1/auth/reset-password', $this->payload($user, $code));

        $response->assertOk();
        $response->assertJsonPath('data.type', 'reset-password');
        $response->assertJsonPath('data.status', 'reset');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('password_reset_codes', 0);
        self::assertSame(
            1,
            AuditLog::query()->where('action', AuditAction::PASSWORD_RESET_COMPLETED)->count(),
        );

        // The new password actually works, end to end.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    public function test_a_wrong_code_is_rejected_and_counts_against_the_attempt_limit(): void
    {
        $user = $this->makeUser();
        app(PasswordResetCodes::class)->issue($user);

        $response = $this->postJson('/api/v1/auth/reset-password', $this->payload($user, '000000'));

        $response->assertUnprocessable();
        self::assertSame(
            'This reset code is invalid or expired.',
            $response->json('error.errors.code.0'),
        );
        $this->assertDatabaseHas('password_reset_codes', ['user_id' => $user->id, 'attempts' => 1]);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = $this->makeUser();
        $code = app(PasswordResetCodes::class)->issue($user);
        PasswordResetCode::query()->where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/reset-password', $this->payload($user, $code))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'This reset code is invalid or expired.');
    }

    public function test_a_code_stops_working_after_the_attempt_limit_even_if_the_last_guess_is_right(): void
    {
        config(['auth.password_reset.max_attempts' => 3]);
        $user = $this->makeUser();
        $code = app(PasswordResetCodes::class)->issue($user);
        $wrong = $code === '123456' ? '654321' : '123456';

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/reset-password', $this->payload($user, $wrong))
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/reset-password', $this->payload($user, $code))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'This reset code is invalid or expired.');
        self::assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_a_weak_new_password_is_rejected(): void
    {
        $user = $this->makeUser();
        $code = app(PasswordResetCodes::class)->issue($user);

        $response = $this->postJson('/api/v1/auth/reset-password', $this->payload($user, $code, 'all-lowercase-no-digits'));

        $response->assertUnprocessable();
        self::assertArrayHasKey('password', $response->json('error.errors'));
        // A rejected password must never consume the code.
        $this->assertDatabaseCount('password_reset_codes', 1);
    }

    public function test_a_disabled_account_cannot_reset_its_password(): void
    {
        $user = $this->makeUser(status: UserStatus::Disabled);
        $code = app(PasswordResetCodes::class)->issue($user);

        $this->postJson('/api/v1/auth/reset-password', $this->payload($user, $code))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'This reset code is invalid or expired.');
    }

    public function test_a_queue_kiosk_account_cannot_reset_its_password_through_this_endpoint(): void
    {
        $user = $this->makeUser(role: UserRole::QueueKiosk);
        $code = app(PasswordResetCodes::class)->issue($user);

        $this->postJson('/api/v1/auth/reset-password', $this->payload($user, $code))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'This reset code is invalid or expired.');
    }
}

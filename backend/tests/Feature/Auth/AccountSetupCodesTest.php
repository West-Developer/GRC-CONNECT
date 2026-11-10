<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AccountSetupCode;
use App\Models\User;
use App\Support\Auth\AccountSetupCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stakeholder Doc 14: the one-time setup code is six digits, so it is only
 * acceptable with an expiry and a guess limit.
 */
final class AccountSetupCodesTest extends TestCase
{
    use RefreshDatabase;

    private function pendingStudent(string $email = 'pending.code@grc.test'): User
    {
        return User::create([
            'name' => 'Pending Student',
            'email' => $email,
            'password' => 'unusable-placeholder',
            'role' => UserRole::Student,
            'status' => UserStatus::Disabled,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function activationPayload(User $user, string $code): array
    {
        return [
            'email' => $user->email,
            'code' => $code,
            'password' => 'New-Secure-Password1!',
            'password_confirmation' => 'New-Secure-Password1!',
        ];
    }

    public function test_an_issued_code_is_six_digits_and_only_its_hash_is_stored(): void
    {
        $user = $this->pendingStudent();

        $code = app(AccountSetupCodes::class)->issue($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $row = AccountSetupCode::query()->where('user_id', $user->id)->sole();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertSame(0, $row->attempts);
        $this->assertTrue($row->expires_at->isFuture());
    }

    public function test_issuing_again_replaces_the_previous_code(): void
    {
        $user = $this->pendingStudent();
        $codes = app(AccountSetupCodes::class);

        $first = $codes->issue($user);
        $second = $codes->issue($user);

        $this->assertSame(1, AccountSetupCode::query()->where('user_id', $user->id)->count());
        $this->assertTrue($codes->attempt($user, $second));
        if ($first !== $second) {
            $this->assertFalse($codes->attempt($user, $first));
        }
    }

    public function test_a_code_stops_working_after_too_many_wrong_guesses(): void
    {
        config(['auth.setup_codes.max_attempts' => 3]);
        $user = $this->pendingStudent();
        $codes = app(AccountSetupCodes::class);
        $code = $codes->issue($user);
        $wrong = $code === '123456' ? '654321' : '123456';

        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($codes->attempt($user, $wrong));
        }

        $this->assertSame(3, AccountSetupCode::query()->where('user_id', $user->id)->value('attempts'));
        // The right code no longer helps once the guesses are used up.
        $this->assertFalse($codes->attempt($user, $code));
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = $this->pendingStudent();
        $codes = app(AccountSetupCodes::class);
        $code = $codes->issue($user);

        AccountSetupCode::query()->where('user_id', $user->id)->update(['expires_at' => now()->subSecond()]);

        $this->assertFalse($codes->attempt($user, $code));
    }

    public function test_a_code_can_be_consumed_only_once(): void
    {
        $user = $this->pendingStudent();
        $codes = app(AccountSetupCodes::class);
        $codes->issue($user);

        $this->assertTrue($codes->consume($user));
        $this->assertFalse($codes->consume($user));
    }

    public function test_the_endpoint_activates_with_the_right_code_and_never_lets_it_be_reused(): void
    {
        $user = $this->pendingStudent();
        $code = app(AccountSetupCodes::class)->issue($user);

        $this->postJson('/api/v1/auth/account-setup', $this->activationPayload($user, $code))
            ->assertOk();

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->assertSame(0, AccountSetupCode::query()->where('user_id', $user->id)->count());

        $this->postJson('/api/v1/auth/account-setup', $this->activationPayload($user, $code))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'The setup code is invalid or expired.');
    }

    public function test_a_pending_professor_activates_through_the_faculty_endpoint(): void
    {
        $professor = User::create([
            'name' => 'Pending Professor',
            'email' => 'pending.prof.code@grc.test',
            'password' => 'unusable-placeholder',
            'role' => UserRole::Faculty,
            'status' => UserStatus::Disabled,
        ]);
        $code = app(AccountSetupCodes::class)->issue($professor);

        $this->postJson('/api/v1/auth/faculty-account-setup', $this->activationPayload($professor, $code) + [
            'name' => 'Prof. Setup Code',
        ])->assertOk();

        $activated = $professor->fresh();
        $this->assertSame(UserStatus::Active, $activated->status);
        $this->assertSame('Prof. Setup Code', $activated->name);
        $this->assertSame(0, AccountSetupCode::query()->where('user_id', $professor->id)->count());
    }

    public function test_the_endpoint_counts_wrong_guesses_and_then_refuses_the_right_code(): void
    {
        config(['auth.setup_codes.max_attempts' => 3]);
        $user = $this->pendingStudent();
        $code = app(AccountSetupCodes::class)->issue($user);
        $wrong = $code === '123456' ? '654321' : '123456';

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/account-setup', $this->activationPayload($user, $wrong))
                ->assertUnprocessable()
                ->assertJsonPath('error.errors.code.0', 'The setup code is invalid or expired.');
        }

        $this->assertSame(3, AccountSetupCode::query()->where('user_id', $user->id)->value('attempts'));

        $this->postJson('/api/v1/auth/account-setup', $this->activationPayload($user, $code))
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.code.0', 'The setup code is invalid or expired.');
        $this->assertSame(UserStatus::Disabled, $user->fresh()->status);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function weakPasswordProvider(): array
    {
        return [
            'too short' => ['Short1!'],
            'missing uppercase' => ['new-secure-password1!'],
            'missing lowercase' => ['NEW-SECURE-PASSWORD1!'],
            'missing a number' => ['New-Secure-Password!'],
            'missing a symbol' => ['NewSecurePassword1'],
        ];
    }

    /**
     * @dataProvider weakPasswordProvider
     */
    public function test_a_password_that_does_not_meet_the_complexity_rule_is_rejected(string $weakPassword): void
    {
        $user = $this->pendingStudent();
        $code = app(AccountSetupCodes::class)->issue($user);

        $response = $this->postJson('/api/v1/auth/account-setup', [
            'email' => $user->email,
            'code' => $code,
            'password' => $weakPassword,
            'password_confirmation' => $weakPassword,
        ]);

        $response->assertUnprocessable();
        self::assertArrayHasKey('password', $response->json('error.errors'));
        // A rejected password must never consume the one-time code — the
        // student can simply retry with a stronger password.
        $this->assertSame(UserStatus::Disabled, $user->fresh()->status);
        $this->assertSame(1, AccountSetupCode::query()->where('user_id', $user->id)->count());
    }

    public function test_a_password_meeting_every_complexity_class_is_accepted(): void
    {
        $user = $this->pendingStudent();
        $code = app(AccountSetupCodes::class)->issue($user);

        $this->postJson('/api/v1/auth/account-setup', $this->activationPayload($user, $code))
            ->assertOk();

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_the_endpoint_rejects_a_code_that_is_not_six_digits(): void
    {
        $user = $this->pendingStudent();
        app(AccountSetupCodes::class)->issue($user);

        foreach (['12345', '1234567', 'abcdef', '12 456'] as $notSixDigits) {
            $this->postJson('/api/v1/auth/account-setup', $this->activationPayload($user, $notSixDigits))
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }

        // Format errors never touch the guess counter.
        $this->assertSame(0, AccountSetupCode::query()->where('user_id', $user->id)->value('attempts'));
    }
}

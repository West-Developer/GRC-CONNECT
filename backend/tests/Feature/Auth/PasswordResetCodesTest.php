<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Support\Auth\PasswordResetCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sibling of `AccountSetupCodesTest` — same six-digit/hash/expiry/
 * guess-limit shape, its own table and config.
 */
final class PasswordResetCodesTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(string $email = 'reset.code@grc.test'): User
    {
        return User::create([
            'name' => 'Active User',
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_an_issued_code_is_six_digits_and_only_its_hash_is_stored(): void
    {
        $user = $this->activeUser();

        $code = app(PasswordResetCodes::class)->issue($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $row = PasswordResetCode::query()->where('user_id', $user->id)->sole();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertSame(0, $row->attempts);
        $this->assertTrue($row->expires_at->isFuture());
    }

    public function test_issuing_again_replaces_the_previous_code(): void
    {
        $user = $this->activeUser();
        $codes = app(PasswordResetCodes::class);

        $first = $codes->issue($user);
        $second = $codes->issue($user);

        $this->assertSame(1, PasswordResetCode::query()->where('user_id', $user->id)->count());
        $this->assertTrue($codes->attempt($user, $second));
        if ($first !== $second) {
            $this->assertFalse($codes->attempt($user, $first));
        }
    }

    public function test_a_code_stops_working_after_too_many_wrong_guesses(): void
    {
        config(['auth.password_reset.max_attempts' => 3]);
        $user = $this->activeUser();
        $codes = app(PasswordResetCodes::class);
        $code = $codes->issue($user);
        $wrong = $code === '123456' ? '654321' : '123456';

        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($codes->attempt($user, $wrong));
        }

        $this->assertSame(3, PasswordResetCode::query()->where('user_id', $user->id)->value('attempts'));
        $this->assertFalse($codes->attempt($user, $code));
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = $this->activeUser();
        $codes = app(PasswordResetCodes::class);
        $code = $codes->issue($user);

        PasswordResetCode::query()->where('user_id', $user->id)->update(['expires_at' => now()->subSecond()]);

        $this->assertFalse($codes->attempt($user, $code));
    }

    public function test_a_code_can_be_consumed_only_once(): void
    {
        $user = $this->activeUser();
        $codes = app(PasswordResetCodes::class);
        $codes->issue($user);

        $this->assertTrue($codes->consume($user));
        $this->assertFalse($codes->consume($user));
    }
}

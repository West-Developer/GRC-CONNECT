<?php

namespace Tests\Feature\Auth;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\LoginOtpChallenge;
use App\Models\User;
use App\Support\Auth\LoginOtpChallenges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sibling of `AccountSetupCodesTest`/`PasswordResetCodesTest` — same
 * six-digit/hash/expiry/guess-limit shape, plus the opaque lookup token this
 * challenge additionally carries.
 */
final class LoginOtpChallengesTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(string $email = 'otp.challenge@grc.test'): User
    {
        return User::create([
            'name' => 'Active User',
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_an_issued_challenge_has_a_six_digit_code_and_only_hashes_are_stored(): void
    {
        $user = $this->activeUser();

        $issued = app(LoginOtpChallenges::class)->issue($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $issued['code']);
        $this->assertNotEmpty($issued['token']);
        $row = LoginOtpChallenge::query()->where('user_id', $user->id)->sole();
        $this->assertNotSame($issued['code'], $row->code_hash);
        $this->assertNotSame($issued['token'], $row->token_hash);
        $this->assertSame(0, $row->attempts);
        $this->assertTrue($row->expires_at->isFuture());
    }

    public function test_issuing_again_replaces_the_previous_challenge_and_invalidates_its_token(): void
    {
        $user = $this->activeUser();
        $challenges = app(LoginOtpChallenges::class);

        $first = $challenges->issue($user);
        $second = $challenges->issue($user);

        $this->assertSame(1, LoginOtpChallenge::query()->where('user_id', $user->id)->count());
        $this->assertSame('ok', $challenges->attempt($second['token'], $second['code'])['status']);
        if ($first['token'] !== $second['token']) {
            $this->assertSame('expired_or_missing', $challenges->attempt($first['token'], $first['code'])['status']);
        }
    }

    public function test_finding_by_token_returns_the_matching_row(): void
    {
        $user = $this->activeUser();
        $issued = app(LoginOtpChallenges::class)->issue($user);

        $found = app(LoginOtpChallenges::class)->findByToken($issued['token']);

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->user_id);
    }

    public function test_finding_by_an_unknown_token_returns_null(): void
    {
        $this->assertNull(app(LoginOtpChallenges::class)->findByToken('not-a-real-token'));
    }

    public function test_a_wrong_code_is_rejected_but_still_identifies_the_user(): void
    {
        $user = $this->activeUser();
        $challenges = app(LoginOtpChallenges::class);
        $issued = $challenges->issue($user);
        $wrong = $issued['code'] === '123456' ? '654321' : '123456';

        $result = $challenges->attempt($issued['token'], $wrong);

        $this->assertSame('invalid_code', $result['status']);
        $this->assertNotNull($result['user']);
        $this->assertSame($user->id, $result['user']->id);
    }

    public function test_a_challenge_stops_working_after_too_many_wrong_guesses(): void
    {
        config(['auth.login_otp.max_attempts' => 3]);
        $user = $this->activeUser();
        $challenges = app(LoginOtpChallenges::class);
        $issued = $challenges->issue($user);
        $wrong = $issued['code'] === '123456' ? '654321' : '123456';

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('invalid_code', $challenges->attempt($issued['token'], $wrong)['status']);
        }

        $this->assertSame(3, LoginOtpChallenge::query()->where('user_id', $user->id)->value('attempts'));
        $this->assertSame('expired_or_missing', $challenges->attempt($issued['token'], $issued['code'])['status']);
    }

    public function test_an_expired_challenge_is_rejected(): void
    {
        $user = $this->activeUser();
        $challenges = app(LoginOtpChallenges::class);
        $issued = $challenges->issue($user);

        LoginOtpChallenge::query()->where('user_id', $user->id)->update(['expires_at' => now()->subSecond()]);

        $this->assertSame('expired_or_missing', $challenges->attempt($issued['token'], $issued['code'])['status']);
    }

    public function test_an_unknown_token_is_rejected_with_no_user_identified(): void
    {
        $challenges = app(LoginOtpChallenges::class);

        $result = $challenges->attempt('not-a-real-token', '123456');

        $this->assertSame('expired_or_missing', $result['status']);
        $this->assertNull($result['user']);
    }

    public function test_a_challenge_can_be_consumed_only_once(): void
    {
        $user = $this->activeUser();
        $challenges = app(LoginOtpChallenges::class);
        $challenges->issue($user);

        $this->assertTrue($challenges->consume($user));
        $this->assertFalse($challenges->consume($user));
    }
}

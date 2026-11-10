<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sanctum stamps `last_used_at` on every authenticated request, which turned
 * every GET poll into a database write. `App\Models\PersonalAccessToken`
 * throttles that stamp to once per five minutes (ADR 0029).
 */
final class PersonalAccessTokenTouchThrottleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: PersonalAccessToken} */
    private function tokenFor(): array
    {
        $user = User::create([
            'name' => 'Throttle Student', 'email' => 'throttle@grc.test',
            'password' => 'correct-horse-battery-staple', 'role' => UserRole::Student, 'status' => UserStatus::Active, 'last_otp_verified_at' => now(),
        ]);
        $newToken = $user->createToken('throttle-test');

        return [$newToken->plainTextToken, $newToken->accessToken];
    }

    private function hitApi(string $plainText): void
    {
        $this->withToken($plainText)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_the_token_is_registered_with_the_throttled_model(): void
    {
        [, $token] = $this->tokenFor();

        self::assertInstanceOf(PersonalAccessToken::class, $token);
    }

    public function test_a_token_used_within_five_minutes_is_not_written_again(): void
    {
        [$plain, $token] = $this->tokenFor();
        $recent = now()->subMinute()->startOfSecond();
        DB::table('personal_access_tokens')->where('id', $token->id)->update(['last_used_at' => $recent]);

        $this->hitApi($plain);

        self::assertTrue($recent->equalTo(PersonalAccessToken::query()->findOrFail($token->id)->last_used_at));
    }

    public function test_an_authenticated_poll_inside_the_window_issues_no_token_update(): void
    {
        [$plain, $token] = $this->tokenFor();
        DB::table('personal_access_tokens')->where('id', $token->id)->update(['last_used_at' => now()->subMinute()]);

        $tokenUpdates = 0;
        DB::listen(function ($query) use (&$tokenUpdates): void {
            if (str_starts_with(strtolower($query->sql), 'update `personal_access_tokens`')) {
                $tokenUpdates++;
            }
        });

        $this->hitApi($plain);
        $this->hitApi($plain);

        self::assertSame(0, $tokenUpdates);
    }

    public function test_a_token_last_used_over_five_minutes_ago_is_stamped_again(): void
    {
        [$plain, $token] = $this->tokenFor();
        $stale = now()->subMinutes(10)->startOfSecond();
        DB::table('personal_access_tokens')->where('id', $token->id)->update(['last_used_at' => $stale]);

        $this->hitApi($plain);

        self::assertTrue(PersonalAccessToken::query()->findOrFail($token->id)->last_used_at->greaterThan($stale));
    }

    public function test_a_never_used_token_created_over_five_minutes_ago_is_stamped_on_first_use(): void
    {
        [$plain, $token] = $this->tokenFor();
        DB::table('personal_access_tokens')->where('id', $token->id)->update([
            'created_at' => now()->subMinutes(10), 'last_used_at' => null,
        ]);

        $this->hitApi($plain);

        self::assertNotNull(PersonalAccessToken::query()->findOrFail($token->id)->last_used_at);
    }
}

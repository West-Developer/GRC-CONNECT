<?php

namespace Tests\Feature\Actions\Auth;

use App\Actions\Auth\IssueSanctumToken;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class IssueSanctumTokenTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(UserRole $role = UserRole::Student): User
    {
        return User::create([
            'name' => 'Token Test User',
            'email' => 'token.test@grc.test',
            'password' => 'correct-horse-battery-staple',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }

    public function test_it_issues_a_token_stamps_last_login_and_audits_the_given_method(): void
    {
        $user = $this->makeUser();
        $context = new AuditRequestContext('issue-token-test', '127.0.0.1');

        $result = app(IssueSanctumToken::class)->handle($user, 'test-token', 'google', $context);

        self::assertSame($user->id, $result['user']->id);
        self::assertNotNull($result['user']->last_login_at);
        self::assertNotNull(PersonalAccessToken::findToken($result['token']->plainTextToken));

        $log = AuditLog::query()->where('action', AuditAction::LOGIN_SUCCEEDED)->sole();
        self::assertSame($user->id, $log->actor_user_id);
        self::assertSame('google', $log->after_values['method'] ?? null);
    }

    public function test_it_grants_the_kiosk_claim_ability_only_to_the_queue_kiosk_role(): void
    {
        $kiosk = $this->makeUser(UserRole::QueueKiosk);
        $context = new AuditRequestContext('issue-token-kiosk-test', '127.0.0.1');

        $result = app(IssueSanctumToken::class)->handle($kiosk, 'test-token', 'password', $context);

        $token = PersonalAccessToken::findToken($result['token']->plainTextToken);
        self::assertNotNull($token);
        self::assertSame(['queue-kiosk:claim'], $token->abilities);
    }
}

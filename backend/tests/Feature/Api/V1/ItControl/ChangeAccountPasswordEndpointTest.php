<?php

namespace Tests\Feature\Api\V1\ItControl;

use App\Actions\ItControl\ChangeManagedAccountPassword;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\AccountSetupCodes;
use App\Support\Auth\LoginOtpChallenges;
use App\Support\Auth\PasswordResetCodes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ChangeAccountPasswordEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'A-new-strong-passphrase-2026!';

    public function test_changes_both_managed_roles_without_exposing_secrets_and_revokes_only_target_access(): void
    {
        $actor = $this->user('it', UserRole::ItAdmin);
        $actorToken = $actor->createToken('it-test')->plainTextToken;
        foreach ([UserRole::Student, UserRole::Faculty] as $role) {
            $target = $this->user($role->value, $role);
            $oldToken = $target->createToken('old-session')->plainTextToken;
            $target->createToken('another-session');
            app(AccountSetupCodes::class)->issue($target);
            app(PasswordResetCodes::class)->issue($target);
            app(LoginOtpChallenges::class)->issue($target);

            $response = $this->withToken($actorToken)->postJson($this->path($target), $this->payload())
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('data.id', $target->id)->assertJsonMissingPath('data.password');

            $target->refresh();
            self::assertTrue(Hash::check(self::NEW_PASSWORD, $target->password));
            self::assertFalse(Hash::check('Old-password-2026!', $target->password));
            self::assertSame(UserStatus::Active, $target->status);
            self::assertNotNull($target->account_setup_completed_at);
            self::assertNull($target->last_otp_verified_at);
            self::assertSame(0, $target->tokens()->count());
            self::assertSame(1, $actor->tokens()->count());
            foreach (['account_setup_codes', 'password_reset_codes', 'login_otp_challenges'] as $table) {
                $this->assertDatabaseMissing($table, ['user_id' => $target->id]);
            }
            $audit = AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_PASSWORD_CHANGED)->where('auditable_id', $target->id)->sole();
            self::assertSame($actor->id, $audit->actor_user_id);
            self::assertSame(2, $audit->after_values['sessions_revoked']);
            $serialized = $response->getContent().json_encode([$audit->before_values, $audit->after_values], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::NEW_PASSWORD, $serialized);
            self::assertStringNotContainsString($target->password, $serialized);
            $this->app['auth']->forgetGuards();
            $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_rejects_unconfirmed_weak_and_overlong_utf8_passwords_without_mutation(): void
    {
        $target = $this->user('target', UserRole::Student);
        $hash = $target->password;
        $token = $this->user('it', UserRole::ItAdmin)->createToken('it')->plainTextToken;
        $bad = [
            [],
            ['password' => self::NEW_PASSWORD],
            ['password' => self::NEW_PASSWORD, 'password_confirmation' => 'Mismatch1!'],
            ['password' => 'weak', 'password_confirmation' => 'weak'],
            ['password' => str_repeat('é', 36).'Aa1!', 'password_confirmation' => str_repeat('é', 36).'Aa1!'],
        ];
        foreach ($bad as $payload) {
            $this->withToken($token)->postJson($this->path($target), $payload)->assertUnprocessable();
        }
        self::assertSame($hash, $target->refresh()->password);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_PASSWORD_CHANGED)->count());
    }

    public function test_refuses_pending_disabled_and_archived_accounts_without_activating_them(): void
    {
        $token = $this->user('it', UserRole::ItAdmin)->createToken('it')->plainTextToken;
        foreach ([['account_setup_completed_at' => null], ['status' => UserStatus::Disabled], ['archived_at' => now()->startOfSecond()]] as $i => $attributes) {
            $target = $this->user('target-'.$i, UserRole::Student);
            $hash = $target->password;
            $target->forceFill($attributes)->save();
            $this->withToken($token)->postJson($this->path($target), $this->payload())->assertConflict();
            self::assertSame($hash, $target->refresh()->password);
            foreach ($attributes as $key => $value) {
                self::assertEquals($value, $target->getAttribute($key));
            }
        }
    }

    #[DataProvider('otherRoles')]
    public function test_other_callers_cannot_change_a_student_password(UserRole $role): void
    {
        $target = $this->user('target', UserRole::Student);
        $hash = $target->password;
        $token = $this->user('actor', $role)->createToken('other')->plainTextToken;
        $this->withToken($token)->postJson($this->path($target), $this->payload())->assertForbidden();
        self::assertSame($hash, $target->refresh()->password);
    }

    public function test_staff_admin_and_kiosk_targets_are_forbidden_even_with_stale_target_state(): void
    {
        $actor = $this->user('it', UserRole::ItAdmin);
        foreach (UserRole::cases() as $role) {
            if (in_array($role, [UserRole::Student, UserRole::Faculty], true)) {
                continue;
            }
            $target = $this->user('target-'.$role->value, $role);
            // Each caller has its own rate-limit budget; this tests authorization, not throttling.
            $token = $this->user('it-'.$role->value, UserRole::ItAdmin)->createToken('it')->plainTextToken;
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->postJson($this->path($target), $this->payload())->assertForbidden();
        }
        $stale = $this->user('stale', UserRole::Student);
        User::query()->whereKey($stale->id)->update(['role' => UserRole::RegistrarHead->value]);
        $this->expectException(AuthorizationException::class);
        app(ChangeManagedAccountPassword::class)->execute($stale, self::NEW_PASSWORD, $actor, new AuditRequestContext('stale', null));
    }

    public function test_super_admin_acting_as_it_control_is_audited_as_the_real_actor(): void
    {
        $admin = $this->user('admin', UserRole::SuperAdmin);
        $target = $this->user('target', UserRole::Faculty);
        $token = $admin->createToken('acting-it')->plainTextToken;
        $this->withToken($token)->putJson('/api/v1/super-admin/acting-context', ['role' => 'it_admin'])->assertOk();
        $this->withToken($token)->postJson($this->path($target), $this->payload())->assertOk();
        $audit = AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_PASSWORD_CHANGED)->sole();
        self::assertSame($admin->id, $audit->actor_user_id);
        self::assertSame('it_admin', $audit->acting_role);
    }

    public function test_anonymous_requests_are_unauthenticated(): void
    {
        $this->postJson($this->path($this->user('target', UserRole::Student)), $this->payload())->assertUnauthorized();
    }

    public function test_credentials_and_sessions_roll_back_if_the_audit_write_fails(): void
    {
        $actor = $this->user('it', UserRole::ItAdmin);
        $target = $this->user('target', UserRole::Student);
        $hash = $target->password;
        $target->createToken('retained-if-failed');
        app(PasswordResetCodes::class)->issue($target);
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into `audit_logs`')) {
                throw new \RuntimeException('Synthetic audit write failure');
            }
        });

        try {
            app(ChangeManagedAccountPassword::class)->execute($target, self::NEW_PASSWORD, $actor, new AuditRequestContext('rollback', null));
            self::fail('The synthetic audit failure must abort the change.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Synthetic audit write failure', $exception->getMessage());
        }

        self::assertSame($hash, $target->refresh()->password);
        self::assertNotNull($target->last_otp_verified_at);
        self::assertSame(1, $target->tokens()->count());
        $this->assertDatabaseHas('password_reset_codes', ['user_id' => $target->id]);
        self::assertSame(0, AuditLog::query()->where('action', AuditAction::USER_ACCOUNT_PASSWORD_CHANGED)->count());
    }

    /** @return iterable<string, array{UserRole}> */
    public static function otherRoles(): iterable
    {
        foreach (UserRole::cases() as $role) {
            if ($role !== UserRole::ItAdmin) {
                yield $role->value => [$role];
            }
        }
    }

    private function user(string $handle, UserRole $role): User
    {
        return User::create([
            'name' => 'Test '.$handle, 'email' => $handle.'@grc.test', 'password' => 'Old-password-2026!',
            'role' => $role, 'status' => UserStatus::Active, 'account_setup_completed_at' => now(), 'last_otp_verified_at' => now(),
        ]);
    }

    private function path(User $target): string
    {
        return '/api/v1/it-control/accounts/'.$target->id.'/password';
    }

    /** @return array{password: string, password_confirmation: string} */
    private function payload(): array
    {
        return ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
    }
}

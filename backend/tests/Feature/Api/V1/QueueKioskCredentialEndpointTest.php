<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\QueueKioskCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class QueueKioskCredentialEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_callers_cannot_view_or_rotate_the_queue_kiosk_credential(): void
    {
        $this->getJson('/api/v1/queue-kiosk-credential')->assertUnauthorized();
        $this->putJson('/api/v1/queue-kiosk-credential', ['password' => 'new-pass'])->assertUnauthorized();
    }

    #[DataProvider('nonAccountingRoles')]
    public function test_non_accounting_roles_cannot_view_or_rotate_the_queue_kiosk_credential(UserRole $role): void
    {
        $token = $this->tokenFor($role);

        $this->withToken($token)->getJson('/api/v1/queue-kiosk-credential')->assertForbidden();
        $this->withToken($token)->putJson('/api/v1/queue-kiosk-credential', ['password' => 'new-pass'])->assertForbidden();
    }

    public function test_accounting_staff_can_view_the_exact_no_store_credential_resource_and_the_view_is_audited(): void
    {
        [$credential, $kiosk] = $this->makeCredential();
        $actor = $this->user(UserRole::AccountingStaff, 'accounting.view@grc.test');
        $token = $actor->createToken('accounting-view')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/queue-kiosk-credential')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [
                'type' => 'queue_kiosk_credential',
                'email' => $kiosk->email,
                'password' => 'current-pass',
            ]]);

        $audit = AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole();
        self::assertSame($actor->id, $audit->actor_user_id);
        self::assertSame(AuditAction::QUEUE_KIOSK_CREDENTIAL_VIEWED, $audit->action);
        self::assertSame(AuditableType::QUEUE_KIOSK_CREDENTIAL, $audit->auditable_type);
        self::assertSame($credential->id, $audit->auditable_id);
        self::assertSame(['user_id' => $kiosk->id], $audit->before_values);
        self::assertNull($audit->after_values);
    }

    public function test_accounting_password_rotation_requires_at_least_eight_characters(): void
    {
        $this->makeCredential();
        $token = $this->user(UserRole::AccountingStaff, 'accounting.validation@grc.test')
            ->createToken('accounting-validation')
            ->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/queue-kiosk-credential', ['password' => '1234567'])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.password.0', 'The password field must be at least 8 characters.');
    }

    public function test_a_256_character_password_is_rejected_without_changing_any_credential_state(): void
    {
        [$credential, $kiosk] = $this->makeCredential();
        $actor = $this->user(UserRole::AccountingStaff, 'accounting.max-length@grc.test');
        $credential->update(['updated_by' => $actor->id]);
        $accountingToken = $actor->createToken('accounting-max-length')->plainTextToken;
        $kiosk->createToken('kiosk-existing');
        $oldPasswordHash = $kiosk->getRawOriginal('password');
        $oldCiphertext = $credential->secret_ciphertext;
        $oldUpdater = $credential->updated_by;
        $oldTokenIds = $kiosk->tokens()->pluck('id')->all();

        $this->withToken($accountingToken)
            ->putJson('/api/v1/queue-kiosk-credential', [
                'password' => str_repeat('x', 256),
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.errors.password.0',
                'The password field must not be greater than 255 characters.',
            );

        self::assertSame($oldPasswordHash, $kiosk->fresh()?->getRawOriginal('password'));
        self::assertSame($oldCiphertext, $credential->fresh()?->secret_ciphertext);
        self::assertSame($oldUpdater, $credential->fresh()?->updated_by);
        self::assertSame($oldTokenIds, $kiosk->tokens()->pluck('id')->all());
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    public function test_accounting_staff_rotation_updates_both_secret_representations_revokes_kiosk_tokens_and_audits_safe_metadata(): void
    {
        [$credential, $kiosk] = $this->makeCredential();
        $actor = $this->user(UserRole::AccountingStaff, 'accounting.rotate@grc.test');
        $accountingToken = $actor->createToken('accounting-rotate')->plainTextToken;
        $firstKioskToken = $kiosk->createToken('kiosk-one')->plainTextToken;
        $secondKioskToken = $kiosk->createToken('kiosk-two')->plainTextToken;
        $oldPasswordHash = $kiosk->password;
        $oldCiphertext = $credential->secret_ciphertext;

        $response = $this->withToken($accountingToken)
            ->putJson('/api/v1/queue-kiosk-credential', ['password' => 'new-pass'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [
                'type' => 'queue_kiosk_credential',
                'email' => $kiosk->email,
                'password' => 'new-pass',
            ]]);

        self::assertTrue(Hash::check('new-pass', $kiosk->fresh()->password));
        self::assertSame('new-pass', Crypt::decryptString($credential->fresh()->secret_ciphertext));
        self::assertSame(0, $kiosk->fresh()->tokens()->count());

        $audit = AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->sole();
        self::assertSame(AuditAction::QUEUE_KIOSK_PASSWORD_CHANGED, $audit->action);
        self::assertSame(AuditableType::QUEUE_KIOSK_CREDENTIAL, $audit->auditable_type);
        self::assertSame([
            'user_id' => $kiosk->id,
            'rotated_at' => $credential->updated_at?->toISOString(),
        ], $audit->before_values);
        self::assertSame([
            'user_id' => $kiosk->id,
            'rotated_at' => $credential->fresh()->updated_at?->toISOString(),
            'revoked_session_count' => 2,
        ], $audit->after_values);

        $serializedAudit = json_encode([$audit->before_values, $audit->after_values], JSON_THROW_ON_ERROR);
        $serializedResponse = $response->json();
        foreach ([$oldPasswordHash, $oldCiphertext, $firstKioskToken, $secondKioskToken] as $secret) {
            self::assertStringNotContainsString($secret, $serializedAudit);
            self::assertStringNotContainsString($secret, json_encode($serializedResponse, JSON_THROW_ON_ERROR));
        }
        self::assertStringNotContainsString('current-pass', $serializedAudit);
        self::assertStringNotContainsString('new-pass', $serializedAudit);
    }

    public function test_an_audit_write_failure_rolls_back_password_credential_updater_and_token_revocation(): void
    {
        [$credential, $kiosk] = $this->makeCredential();
        $actor = $this->user(UserRole::AccountingStaff, 'accounting.audit-failure@grc.test');
        $priorUpdater = $this->user(UserRole::AccountingStaff, 'accounting.prior-updater@grc.test');
        $credential->update(['updated_by' => $priorUpdater->id]);
        $accountingToken = $actor->createToken('accounting-audit-failure')->plainTextToken;
        $kiosk->createToken('kiosk-one');
        $kiosk->createToken('kiosk-two');
        $oldPasswordHash = $kiosk->getRawOriginal('password');
        $oldCiphertext = $credential->secret_ciphertext;
        $oldTokenIds = $kiosk->tokens()->pluck('id')->all();

        AuditLog::creating(static function (): never {
            throw new RuntimeException('Injected audit write failure.');
        });
        $this->withoutExceptionHandling();

        try {
            $this->withToken($accountingToken)
                ->putJson('/api/v1/queue-kiosk-credential', ['password' => 'new-pass']);
            self::fail('The injected audit write failure must escape the credential transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected audit write failure.', $exception->getMessage());
            self::assertStringNotContainsString('current-pass', $exception->getMessage());
            self::assertStringNotContainsString('new-pass', $exception->getMessage());
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }

        self::assertSame($oldPasswordHash, $kiosk->fresh()?->getRawOriginal('password'));
        self::assertSame($oldCiphertext, $credential->fresh()?->secret_ciphertext);
        self::assertSame($priorUpdater->id, $credential->fresh()?->updated_by);
        self::assertSame($oldTokenIds, $kiosk->tokens()->pluck('id')->all());
        self::assertSame(0, AuditLog::query()->where('action', '!=', AuditAction::LOGIN_SUCCEEDED)->count());
    }

    /**
     * @return iterable<string, array{UserRole}>
     */
    public static function nonAccountingRoles(): iterable
    {
        yield 'Student' => [UserRole::Student];
        yield 'Registrar Staff' => [UserRole::RegistrarStaff];
        yield 'IT Admin' => [UserRole::ItAdmin];
        yield 'Queue Kiosk' => [UserRole::QueueKiosk];
    }

    /**
     * @return array{QueueKioskCredential, User}
     */
    private function makeCredential(): array
    {
        $kiosk = $this->user(UserRole::QueueKiosk, 'queue-kiosk@grc.test');
        $credential = QueueKioskCredential::create([
            'user_id' => $kiosk->id,
            'secret_ciphertext' => Crypt::encryptString('current-pass'),
        ]);

        return [$credential, $kiosk];
    }

    private function user(UserRole $role, string $email): User
    {
        return User::create([
            'name' => $role->label(),
            'email' => $email,
            'password' => 'current-pass',
            'role' => $role,
            'status' => UserStatus::Active,
            'last_otp_verified_at' => now(),
        ]);
    }

    private function tokenFor(UserRole $role): string
    {
        return $this->user($role, $role->value.'@grc.test')->createToken('test-token')->plainTextToken;
    }
}

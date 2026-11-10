<?php

namespace Tests\Feature\Database;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\QueueKioskCredential;
use App\Models\User;
use Database\Seeders\QueueKioskSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

final class QueueKioskSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_active_queue_kiosk_user_with_an_encrypted_shared_password(): void
    {
        $this->seed(QueueKioskSeeder::class);

        $user = User::query()->where('email', 'queue@grc.com')->sole();
        $credential = QueueKioskCredential::query()->where('user_id', $user->id)->sole();

        self::assertSame(UserRole::QueueKiosk, $user->role);
        self::assertSame(UserStatus::Active, $user->status);
        self::assertTrue(Hash::check('password', $user->password));
        self::assertSame('password', Crypt::decryptString($credential->secret_ciphertext));
        self::assertSame(1, User::query()->where('role', UserRole::QueueKiosk->value)->count());
        self::assertSame(1, QueueKioskCredential::query()->count());
    }

    public function test_reseeding_preserves_the_device_user_and_credential_rows(): void
    {
        $this->seed(QueueKioskSeeder::class);

        $userId = User::query()->where('email', 'queue@grc.com')->sole()->id;
        $credentialId = QueueKioskCredential::query()->where('user_id', $userId)->sole()->id;

        $this->seed(QueueKioskSeeder::class);

        self::assertSame($userId, User::query()->where('email', 'queue@grc.com')->sole()->id);
        self::assertSame($credentialId, QueueKioskCredential::query()->where('user_id', $userId)->sole()->id);
        self::assertSame(1, QueueKioskCredential::query()->count());
    }

    public function test_it_refuses_production_execution_before_writing_any_fixture(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        app(QueueKioskSeeder::class)->run();
    }

    public function test_production_refusal_leaves_no_device_user_or_credential(): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            app(QueueKioskSeeder::class)->run();
        } catch (RuntimeException) {
            // Expected; the previous test asserts the exception type.
        }

        $this->assertDatabaseMissing('users', ['email' => 'queue@grc.com']);
        $this->assertDatabaseCount('queue_kiosk_credentials', 0);
    }
}

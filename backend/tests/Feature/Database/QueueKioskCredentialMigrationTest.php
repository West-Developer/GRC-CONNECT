<?php

namespace Tests\Feature\Database;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\QueueKioskCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class QueueKioskCredentialMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_kiosk_credentials_table_has_the_required_columns(): void
    {
        self::assertTrue(Schema::hasColumns('queue_kiosk_credentials', [
            'id', 'user_id', 'secret_ciphertext', 'updated_by', 'created_at', 'updated_at',
        ]));
    }

    public function test_a_device_user_can_have_only_one_credential(): void
    {
        $device = $this->makeUser('device.unique@grc.test', UserRole::QueueKiosk);
        $updater = $this->makeUser('updater.unique@grc.test', UserRole::AccountingStaff);

        QueueKioskCredential::create([
            'user_id' => $device->id,
            'secret_ciphertext' => 'first-secret',
            'updated_by' => $updater->id,
        ]);

        $this->expectException(QueryException::class);

        QueueKioskCredential::create([
            'user_id' => $device->id,
            'secret_ciphertext' => 'second-secret',
            'updated_by' => $updater->id,
        ]);
    }

    public function test_deleting_the_device_user_cascades_its_credential(): void
    {
        $device = $this->makeUser('device.cascade@grc.test', UserRole::QueueKiosk);
        $credential = QueueKioskCredential::create([
            'user_id' => $device->id,
            'secret_ciphertext' => 'encrypted-secret',
        ]);

        $device->delete();

        $this->assertDatabaseMissing('queue_kiosk_credentials', ['id' => $credential->id]);
    }

    public function test_deleting_the_updater_nulls_the_credential_updated_by_value(): void
    {
        $device = $this->makeUser('device.updater@grc.test', UserRole::QueueKiosk);
        $updater = $this->makeUser('updater.delete@grc.test', UserRole::AccountingStaff);
        $credential = QueueKioskCredential::create([
            'user_id' => $device->id,
            'secret_ciphertext' => 'encrypted-secret',
            'updated_by' => $updater->id,
        ]);

        $updater->delete();

        self::assertNull($credential->fresh()->updated_by);
    }

    public function test_credential_serialization_never_exposes_its_secret_ciphertext(): void
    {
        $device = $this->makeUser('device.hidden@grc.test', UserRole::QueueKiosk);
        $credential = QueueKioskCredential::create([
            'user_id' => $device->id,
            'secret_ciphertext' => 'encrypted-secret',
        ]);

        self::assertArrayNotHasKey('secret_ciphertext', $credential->toArray());
    }

    public function test_a_device_user_exposes_its_one_credential_relation(): void
    {
        $device = $this->makeUser('device.relation@grc.test', UserRole::QueueKiosk);
        $credential = QueueKioskCredential::create([
            'user_id' => $device->id,
            'secret_ciphertext' => 'encrypted-secret',
        ]);

        self::assertInstanceOf(HasOne::class, $device->queueKioskCredential());
        self::assertSame($credential->id, $device->queueKioskCredential()->sole()->id);
    }

    private function makeUser(string $email, UserRole $role): User
    {
        return User::create([
            'name' => 'Queue Kiosk Test User',
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'status' => UserStatus::Active,
        ]);
    }
}

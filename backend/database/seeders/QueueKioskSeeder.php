<?php

namespace Database\Seeders;

use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\QueueKioskCredential;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds the dedicated local/testing queue kiosk device identity.
 */
final class QueueKioskSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            $user = User::updateOrCreate(
                ['email' => 'queue@grc.com'],
                [
                    'name' => 'Queue Kiosk',
                    'password' => self::PASSWORD,
                    'role' => UserRole::QueueKiosk,
                    'status' => UserStatus::Active,
                ],
            );

            QueueKioskCredential::updateOrCreate(
                ['user_id' => $user->id],
                ['secret_ciphertext' => Crypt::encryptString(self::PASSWORD)],
            );
        });
    }

    /**
     * Synthetic credentials must never reach a production-like environment.
     */
    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'QueueKioskSeeder may only run in the local or testing environment. '
                .'Refusing to seed synthetic credentials into "'.app()->environment().'".',
            );
        }
    }
}

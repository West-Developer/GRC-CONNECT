<?php

namespace Tests\Feature\Actions\Auth;

use App\Actions\QueueKiosk\ChangeQueueKioskPassword;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\AuditLog;
use App\Models\QueueKioskCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AuthenticateUserConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The child login must observe committed fixtures through its independent
     * connection. Exact-row cleanup below keeps this test isolated.
     *
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_an_old_password_observed_before_rotation_cannot_issue_a_surviving_token(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $oldPassword = 'old-kiosk-'.$suffix;
        $newPassword = 'new-kiosk-'.$suffix;
        $kiosk = User::create([
            'name' => 'Concurrent Queue Kiosk',
            'email' => "queue-concurrency-{$suffix}@grc.test",
            'password' => $oldPassword,
            'role' => UserRole::QueueKiosk,
            'status' => UserStatus::Active,
        ]);
        $actor = User::create([
            'name' => 'Concurrent Accounting Staff',
            'email' => "accounting-concurrency-{$suffix}@grc.test",
            'password' => 'accounting-password',
            'role' => UserRole::AccountingStaff,
            'status' => UserStatus::Active,
        ]);
        $credential = QueueKioskCredential::create([
            'user_id' => $kiosk->id,
            'secret_ciphertext' => Crypt::encryptString($oldPassword),
        ]);
        $input = new InputStream;
        $input->write(json_encode([
            'email' => $kiosk->email,
            'password' => $oldPassword,
            'request_id' => 'observed-login-'.$suffix,
        ], JSON_THROW_ON_ERROR)."\n");
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/authenticate-user-after-observation.php'),
        ], base_path(), null, $input, 30);

        try {
            $process->start();
            self::assertTrue(
                $process->waitUntil(
                    static fn (string $type, string $output): bool => str_contains($output, 'OBSERVED'),
                ),
                'The login process did not reach the synchronized post-observation boundary.',
            );

            app(ChangeQueueKioskPassword::class)->execute(
                $credential,
                $newPassword,
                $actor,
                new AuditRequestContext('concurrency-'.$suffix, '127.0.0.1'),
            );

            $input->write("CONTINUE\n");
            $input->close();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertStringContainsString('REJECTED', $process->getOutput());
            self::assertStringNotContainsString('AUTHENTICATED', $process->getOutput());
            self::assertSame(0, $kiosk->tokens()->count());
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            AuditLog::query()->where('request_id', 'concurrency-'.$suffix)->delete();
            AuditLog::query()->where('request_id', 'observed-login-'.$suffix)->delete();
            $kiosk->tokens()->delete();
            $credential->delete();
            $kiosk->delete();
            $actor->delete();
        }
    }
}

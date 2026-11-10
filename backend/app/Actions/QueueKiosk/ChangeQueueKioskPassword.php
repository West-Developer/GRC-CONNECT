<?php

namespace App\Actions\QueueKiosk;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\QueueKioskCredential;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final readonly class ChangeQueueKioskPassword
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @return array{email: string, password: string}
     */
    public function execute(
        QueueKioskCredential $credential,
        string $password,
        User $actor,
        AuditRequestContext $context,
    ): array {
        return DB::transaction(function () use ($credential, $password, $actor, $context): array {
            $lockedCredential = QueueKioskCredential::query()
                ->whereKey($credential->id)
                ->lockForUpdate()
                ->firstOrFail();
            $user = User::query()
                ->whereKey($lockedCredential->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            $previousUpdatedAt = $lockedCredential->updated_at;
            $revokedSessionCount = $user->tokens()->count();

            $user->tokens()->delete();
            $user->update(['password' => $password]);
            $lockedCredential->update([
                'secret_ciphertext' => Crypt::encryptString($password),
                'updated_by' => $actor->id,
            ]);

            $this->auditRecorder->record(
                $actor,
                AuditAction::QUEUE_KIOSK_PASSWORD_CHANGED,
                AuditableType::QUEUE_KIOSK_CREDENTIAL,
                $lockedCredential->id,
                [
                    'user_id' => $user->id,
                    'rotated_at' => $previousUpdatedAt?->toISOString(),
                ],
                [
                    'user_id' => $user->id,
                    'rotated_at' => $lockedCredential->updated_at?->toISOString(),
                    'revoked_session_count' => $revokedSessionCount,
                ],
                null,
                $context,
            );

            return [
                'email' => $user->email,
                'password' => $password,
            ];
        });
    }
}

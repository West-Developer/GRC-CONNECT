<?php

namespace App\Actions\QueueKiosk;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Models\QueueKioskCredential;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\Crypt;

final readonly class ViewQueueKioskCredential
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * @return array{email: string, password: string}
     */
    public function execute(QueueKioskCredential $credential, User $actor, AuditRequestContext $context): array
    {
        $user = $credential->user;
        $password = Crypt::decryptString($credential->secret_ciphertext);

        $this->auditRecorder->record(
            $actor,
            AuditAction::QUEUE_KIOSK_CREDENTIAL_VIEWED,
            AuditableType::QUEUE_KIOSK_CREDENTIAL,
            $credential->id,
            ['user_id' => $user->id],
            null,
            null,
            $context,
        );

        return [
            'email' => $user->email,
            'password' => $password,
        ];
    }
}

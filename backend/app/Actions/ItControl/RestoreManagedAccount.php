<?php

namespace App\Actions\ItControl;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archived account back into the lists (ADR 0054). It becomes active only when it had finished setting
 * up its login and was not retired on purpose (a merged duplicate keeps its deactivation reason); otherwise it
 * stays disabled, exactly as it was before it was archived, and the usual invitation or reactivation applies.
 */
final class RestoreManagedAccount
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function execute(User $target, User $actor, AuditRequestContext $context): User
    {
        return DB::transaction(function () use ($target, $actor, $context): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($locked->archived_at === null) {
                abort(409, 'This account is not archived.');
            }

            $status = $locked->account_setup_completed_at !== null && $locked->deactivation_reason === null
                ? UserStatus::Active
                : UserStatus::Disabled;

            $locked->forceFill(['status' => $status, 'archived_at' => null])->save();

            $this->auditRecorder->record(
                $actor,
                AuditAction::USER_ACCOUNT_RESTORED,
                AuditableType::USER_ACCOUNT,
                $locked->id,
                ['status' => UserStatus::Disabled->value, 'is_archived' => true],
                ['status' => $status->value, 'is_archived' => false],
                null,
                $context,
            );

            return $locked->refresh();
        });
    }
}

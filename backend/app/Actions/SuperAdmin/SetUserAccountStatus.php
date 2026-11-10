<?php

namespace App\Actions\SuperAdmin;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class SetUserAccountStatus
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
    ) {}

    public function execute(
        User $target,
        UserStatus $newStatus,
        string $reason,
        User $actor,
        AuditRequestContext $context,
    ): User {
        if ($newStatus === UserStatus::Active && $target->account_setup_completed_at === null) {
            abort(409, 'Resend the setup invitation instead.');
        }

        $updated = DB::transaction(function () use ($target, $newStatus, $reason, $actor, $context): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($newStatus === UserStatus::Active && $locked->account_setup_completed_at === null) {
                abort(409, 'Resend the setup invitation instead.');
            }

            $before = [
                'status' => $locked->status->value,
            ];

            $after = [
                'status' => $newStatus->value,
            ];

            $locked->forceFill([
                'status' => $newStatus,
            ])->save();

            if ($newStatus === UserStatus::Disabled) {
                $locked->tokens()->delete();
            }

            $action = $newStatus === UserStatus::Disabled
                ? AuditAction::USER_ACCOUNT_DEACTIVATED
                : AuditAction::USER_ACCOUNT_REACTIVATED;

            $this->auditRecorder->record(
                $actor,
                $action,
                AuditableType::USER_ACCOUNT,
                $locked->id,
                $before,
                $after,
                $reason,
                $context,
            );

            return $locked->refresh();
        });

        return $updated;
    }
}
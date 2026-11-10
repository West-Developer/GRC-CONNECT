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
 * Retires a Student or Faculty account without touching anyone's records (ADR 0054): the account is disabled,
 * its sessions and any unused sign-in, setup or reset code are revoked, and it leaves the default lists.
 * Activation also refuses an archived account, so a code that was already emailed cannot bring it back.
 */
final class ArchiveManagedAccount
{
    public function __construct(private readonly AuditRecorder $auditRecorder) {}

    public function execute(User $target, User $actor, AuditRequestContext $context): User
    {
        return DB::transaction(function () use ($target, $actor, $context): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($locked->archived_at !== null) {
                abort(409, 'This account is already archived.');
            }

            $before = ['status' => $locked->status->value, 'is_archived' => false];

            $locked->forceFill(['status' => UserStatus::Disabled, 'archived_at' => now()])->save();
            $locked->tokens()->delete();
            foreach (['account_setup_codes', 'password_reset_codes', 'login_otp_challenges'] as $table) {
                DB::table($table)->where('user_id', $locked->id)->delete();
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::USER_ACCOUNT_ARCHIVED,
                AuditableType::USER_ACCOUNT,
                $locked->id,
                $before,
                ['status' => UserStatus::Disabled->value, 'is_archived' => true],
                null,
                $context,
            );

            return $locked->refresh();
        });
    }
}

<?php

namespace App\Actions\ItControl;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

final readonly class ChangeManagedAccountPassword
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(User $target, string $password, User $actor, AuditRequestContext $context): User
    {
        return DB::transaction(function () use ($target, $password, $actor, $context): User {
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('manage-it-control-account', $locked);

            if ($locked->archived_at !== null || $locked->status !== UserStatus::Active || $locked->account_setup_completed_at === null) {
                abort(409, 'Only an active account that has completed setup can have its password changed.');
            }

            $locked->forceFill(['password' => Hash::make($password), 'last_otp_verified_at' => null])->save();
            $revoked = $locked->tokens()->delete();
            foreach (['account_setup_codes', 'password_reset_codes', 'login_otp_challenges'] as $table) {
                DB::table($table)->where('user_id', $locked->id)->delete();
            }

            $this->auditRecorder->record(
                $actor,
                AuditAction::USER_ACCOUNT_PASSWORD_CHANGED,
                AuditableType::USER_ACCOUNT,
                $locked->id,
                null,
                ['sessions_revoked' => $revoked, 'codes_revoked' => true],
                null,
                $context,
            );

            return $locked->refresh();
        });
    }
}

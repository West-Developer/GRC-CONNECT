<?php

namespace App\Actions\Auth;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\Exceptions\InvalidCredentialsException;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies credentials only — issues no token, sets no `last_login_at`. See
 * `IssueSanctumToken` for what happens once a caller (password login, the
 * login-OTP verification step, or Google sign-in) has a verified `User` in
 * hand.
 *
 * Every failure path raises the same exception so the response cannot
 * distinguish a missing account from a wrong password or a disabled one.
 *
 * A wrong-password/disabled-account failure against a KNOWN account is
 * durably audited: the write happens inside a transaction that commits
 * before this method throws outside it, so the audit row is never rolled
 * back along with the failure it is recording. An unknown email records
 * nothing — there is no `User` row to serve as `AuditRecorder`'s required
 * actor, the same reasoning `SubmitEnrollment`-style actions already follow
 * elsewhere in this codebase.
 */
final class AuthenticateUser
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @throws InvalidCredentialsException
     */
    public function handle(string $email, string $password, AuditRequestContext $context): User
    {
        $user = User::where('email', $email)->first();

        // Hash::check against a dummy hash when the user is missing keeps the
        // response time comparable, so timing cannot reveal account existence.
        if (! $user instanceof User) {
            Hash::check($password, '$2y$12$'.str_repeat('0', 53));

            throw InvalidCredentialsException::make();
        }

        $result = DB::transaction(function () use ($user, $password, $context): array {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();

            $ok = $lockedUser instanceof User
                && Hash::check($password, $lockedUser->password)
                && $lockedUser->status === UserStatus::Active;

            if (! $ok && $lockedUser instanceof User) {
                $this->auditRecorder->record(
                    $lockedUser,
                    AuditAction::LOGIN_FAILED,
                    AuditableType::USER_ACCOUNT,
                    $lockedUser->id,
                    null,
                    null,
                    null,
                    $context,
                );
            }

            return ['ok' => $ok, 'user' => $lockedUser];
        });

        if ($result['ok'] !== true || ! $result['user'] instanceof User) {
            throw InvalidCredentialsException::make();
        }

        return $result['user'];
    }
}

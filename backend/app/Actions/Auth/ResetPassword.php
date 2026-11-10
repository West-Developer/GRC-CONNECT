<?php

namespace App\Actions\Auth;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\PasswordResetCodes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Completes a self-service password reset (auth-hardening batch,
 * 2026-09-29). Structural clone of `ActivateStudentAccount`'s lock/re-check/
 * consume shape, but for an already-ACTIVE account resetting its password
 * rather than a pending one finishing setup — and it additionally revokes
 * every existing Sanctum token (`$locked->tokens()->delete()`), forcing a
 * fresh sign-in everywhere, since a password reset is exactly the moment a
 * compromised session should not be allowed to persist.
 */
final class ResetPassword
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
        private readonly PasswordResetCodes $resetCodes,
    ) {}

    public function handle(
        string $email,
        string $code,
        string $password,
        AuditRequestContext $context,
    ): User {
        $candidate = User::query()->where('email', $email)->first();

        if (
            ! $candidate instanceof User
            || $candidate->status !== UserStatus::Active
            || $candidate->role === UserRole::QueueKiosk
        ) {
            $this->invalidCode();
        }

        // A wrong guess is committed inside attempt(), so it still counts
        // against the code when this method then throws.
        if (! $this->resetCodes->attempt($candidate, $code)) {
            $this->invalidCode();
        }

        $updated = DB::transaction(function () use ($candidate, $password): User {
            $locked = User::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if (
                $locked->status !== UserStatus::Active
                || $locked->role === UserRole::QueueKiosk
                || ! $this->resetCodes->consume($locked)
            ) {
                $this->invalidCode();
            }

            $locked->forceFill(['password' => Hash::make($password)])->save();
            $locked->tokens()->delete();

            return $locked->refresh();
        });

        $this->auditRecorder->record(
            $updated,
            AuditAction::PASSWORD_RESET_COMPLETED,
            AuditableType::USER_ACCOUNT,
            $updated->id,
            null,
            null,
            null,
            $context,
        );

        return $updated;
    }

    private function invalidCode(): never
    {
        throw ValidationException::withMessages([
            'code' => 'This reset code is invalid or expired.',
        ]);
    }
}

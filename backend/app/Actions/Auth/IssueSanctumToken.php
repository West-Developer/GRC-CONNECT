<?php

namespace App\Actions\Auth;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Identity\QueueKioskAccess;
use App\Domain\Identity\UserRole;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues a bearer personal access token for an already-verified `User` and
 * records the login as audited (PRD §9.1). Shared by every path that ends in
 * a successful sign-in — password login (`$method = 'password'`), the
 * login-OTP verification step (`'password_otp'`), and Google sign-in
 * (`'google'`) — so "what actually happened" is always one audit row away,
 * regardless of which door was used.
 */
final class IssueSanctumToken
{
    public function __construct(
        private readonly AuditRecorder $auditRecorder,
    ) {}

    /**
     * @return array{user: User, token: NewAccessToken, expiresAt: ?CarbonImmutable}
     */
    public function handle(User $user, string $tokenName, string $method, AuditRequestContext $context): array
    {
        $expiresAt = $this->expiresAt();

        return DB::transaction(function () use ($user, $tokenName, $method, $expiresAt, $context): array {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $abilities = $lockedUser->role === UserRole::QueueKiosk
                ? [QueueKioskAccess::TOKEN_ABILITY]
                : ['*'];
            $token = $lockedUser->createToken($tokenName, $abilities, $expiresAt?->toDateTime());

            $lockedUser->forceFill(['last_login_at' => CarbonImmutable::now()])->save();

            $this->auditRecorder->record(
                $lockedUser,
                AuditAction::LOGIN_SUCCEEDED,
                AuditableType::USER_ACCOUNT,
                $lockedUser->id,
                null,
                ['method' => $method],
                null,
                $context,
            );

            return [
                'user' => $lockedUser->refresh(),
                'token' => $token,
                'expiresAt' => $expiresAt,
            ];
        });
    }

    /**
     * Derived from config('sanctum.expiration'), which carries a provisional
     * local default pending the approved institutional policy (PRD §17).
     */
    private function expiresAt(): ?CarbonImmutable
    {
        $minutes = config('sanctum.expiration');

        if (! is_numeric($minutes)) {
            return null;
        }

        return CarbonImmutable::now()->addMinutes((int) $minutes);
    }
}

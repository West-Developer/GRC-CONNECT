<?php

namespace App\Http\Middleware;

use App\Domain\Identity\QueueKioskAccess;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class EnsureStudentQueueClaimUsesKiosk
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if (! $actor instanceof User || $actor->role !== UserRole::Student) {
            return $next($request);
        }

        $header = $request->header(QueueKioskAccess::TOKEN_HEADER);
        $kioskToken = is_string($header) ? trim($header) : '';
        $token = $kioskToken === '' ? null : PersonalAccessToken::findToken($kioskToken);

        if (! ($token instanceof PersonalAccessToken)
            || ($token->expires_at !== null && ! $token->expires_at->isFuture())
            || ! ($token->tokenable instanceof User)
            || $token->tokenable->role !== UserRole::QueueKiosk
            || $token->tokenable->status !== UserStatus::Active
            || ! $token->can(QueueKioskAccess::TOKEN_ABILITY)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}

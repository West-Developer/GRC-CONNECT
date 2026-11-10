<?php

namespace App\Http\Middleware;

use App\Domain\Identity\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a still-valid token whose account has since been disabled.
 *
 * Without this, disabling a user would only prevent new logins; tokens issued
 * before the change would keep working until they expired.
 */
final class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->status !== UserStatus::Active) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            throw new AuthenticationException;
        }

        return $next($request);
    }
}

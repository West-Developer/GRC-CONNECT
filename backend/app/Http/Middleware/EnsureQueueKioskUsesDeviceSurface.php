<?php

namespace App\Http\Middleware;

use App\Domain\Identity\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureQueueKioskUsesDeviceSurface
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $user->role === UserRole::QueueKiosk
            && ! $request->routeIs('api.v1.auth.me', 'api.v1.auth.logout')) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}

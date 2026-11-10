<?php

namespace App\Http\Middleware;

use App\Domain\Identity\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse route-level role gate: "is this endpoint reachable by this role at
 * all." Record-level decisions (which rows, which specific record) belong in
 * Policies — see App\Policies. Together the two satisfy PRD §9.4's "validate
 * both role-level and record-level access."
 *
 * Registered as the `role` middleware alias in bootstrap/app.php.
 */
final class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $allowed = array_map(static fn (string $role): UserRole => UserRole::from($role), $roles);

        if (! $user instanceof User || ! in_array($user->role, $allowed, true)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Domain\Identity\ActingContext;
use App\Domain\Identity\Exceptions\ActingContextChangedException;
use App\Domain\Identity\UserRole;
use App\Domain\Organization\CollegeCode;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the signed-in super admin's current office (if any) to the
 * authenticated `User` instance for this request (see `User::getAttributeValue()`).
 *
 * Also enforces the multi-tab guard: one bearer token can be open in several
 * browser tabs, so a tab that sends `X-Acting-Context` describing an office
 * the token no longer actually holds (another tab switched or exited since
 * this tab last synced) is rejected with 409 rather than silently acting as,
 * and being audited as, an office it isn't showing. A tab with no header yet
 * (e.g. its first request after sign-in, before `/auth/me` has returned) is
 * never rejected for that absence alone — only an active mismatch is. The
 * acting-context switch/exit endpoints and session routes are exempt, so a
 * stale tab can always resync or sign out.
 */
final class ApplySuperAdminActingContext
{
    /** @var list<string> */
    private const EXEMPT_ROUTE_NAMES = [
        'api.v1.auth.me',
        'api.v1.auth.logout',
        'api.v1.super-admin.acting-context.update',
        'api.v1.super-admin.acting-context.destroy',
    ];

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        $context = ($token instanceof PersonalAccessToken && is_string($token->acting_role))
            ? ActingContext::fromRequest(
                UserRole::from($token->acting_role),
                $token->acting_college !== null ? CollegeCode::tryFrom($token->acting_college) : null
            )
            : null;

        $this->guardAgainstStaleTab($request, $context);

        $user->applyActingContext($context);

        return $next($request);
    }

    private function guardAgainstStaleTab(Request $request, ?ActingContext $context): void
    {
        $header = $request->header('X-Acting-Context');

        if ($header === null || $request->routeIs(...self::EXEMPT_ROUTE_NAMES)) {
            return;
        }

        if ($header !== self::headerValue($context)) {
            throw ActingContextChangedException::make();
        }
    }

    private static function headerValue(?ActingContext $context): string
    {
        if ($context === null) {
            return 'none';
        }

        return $context->college === null
            ? $context->role->value
            : "{$context->role->value}:{$context->college->value}";
    }
}

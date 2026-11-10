<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A baseline set of security response headers, appended to every response
 * (part of the auth-hardening batch, 2026-09-29). This is a JSON-only API
 * (`bootstrap/app.php` forces JSON rendering for every `api/*` request), so
 * the CSP here mainly protects the rare HTML error page Laravel might still
 * render for a route outside `api/*` — `default-src 'none'` is deliberately
 * maximal since no endpoint under this app ever serves HTML meant to run a
 * script.
 */
final class ApplySecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
        );

        return $response;
    }
}

<?php

namespace App\Support\Http;

/**
 * The web app's own base URL, for links put into emails that carry a secret
 * (a freshman's "view my queue number" link). Always the configured
 * `app.frontend_url`, never a header the caller supplied: an `Origin` or
 * `Referer` is attacker-controlled input, and a link with a token in it must not
 * be steerable to another host. A missing or wildcard value falls back to a
 * safe default.
 */
final class FrontendBaseUrl
{
    public static function resolve(): string
    {
        $configured = (string) config('app.frontend_url', '');

        if (filter_var($configured, FILTER_VALIDATE_URL) && ! str_contains($configured, '*')) {
            return rtrim($configured, '/');
        }

        return app()->isProduction() ? 'https://www.grc-enrollment.tech' : 'http://localhost:3000';
    }
}

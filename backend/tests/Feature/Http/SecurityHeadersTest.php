<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Baseline security response headers (auth-hardening batch, 2026-09-29),
 * appended globally in `bootstrap/app.php`. `/api/v1/health` is the cheapest
 * real, unauthenticated route — this only needs any response, not that
 * endpoint's own behavior.
 */
final class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_baseline_security_headers(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
        );
    }
}

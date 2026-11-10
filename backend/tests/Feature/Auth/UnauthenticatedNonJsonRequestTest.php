<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * Regression coverage for a request that does not send
 * `Accept: application/json` (unlike every other feature test, which uses
 * `getJson()`/`postJson()` and sets that header automatically).
 *
 * Laravel's ApplicationBuilder unconditionally defaults unauthenticated
 * guests to `redirectGuestsTo(fn () => route('login'))`. This is a JSON-only
 * API with no `login` named route, so without the override in
 * bootstrap/app.php, a request like this one crashed with a 500
 * RouteNotFoundException instead of the documented 401 — found while
 * verifying GET /api/v1/programs with plain curl (no Accept header) during
 * this slice's live HTTP proof.
 */
final class UnauthenticatedNonJsonRequestTest extends TestCase
{
    public function test_an_unauthenticated_non_json_request_still_receives_a_clean_401(): void
    {
        $this->get('/api/v1/programs')->assertUnauthorized();
    }
}

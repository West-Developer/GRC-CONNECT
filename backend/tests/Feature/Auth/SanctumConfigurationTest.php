<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Tests\TestCase;

/**
 * Guards the PRD §9.1 bearer-token boundary:
 * "Do not use session cookies, CSRF-cookie endpoints, or `withCredentials`."
 */
final class SanctumConfigurationTest extends TestCase
{
    public function test_no_cookie_or_csrf_route_is_registered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->all();

        foreach ($uris as $uri) {
            $this->assertStringNotContainsString('csrf', $uri);
            $this->assertStringNotContainsString('sanctum', $uri);
        }
    }

    public function test_sanctum_route_registration_is_disabled(): void
    {
        $this->assertFalse(config('sanctum.routes'));
    }

    public function test_no_stateful_domain_is_configured(): void
    {
        $this->assertSame([], config('sanctum.stateful'));
    }

    public function test_no_session_guard_precedes_bearer_token_authentication(): void
    {
        $this->assertSame([], config('sanctum.guard'));
    }

    public function test_no_cookie_or_csrf_middleware_is_configured(): void
    {
        $this->assertSame([], config('sanctum.middleware'));
    }

    public function test_tokens_expire_rather_than_living_forever(): void
    {
        $expiration = config('sanctum.expiration');

        $this->assertNotNull(
            $expiration,
            'Token expiration must not be null; an unset policy must fail safe.',
        );
        $this->assertGreaterThan(0, $expiration);
    }

    public function test_user_model_can_issue_api_tokens(): void
    {
        $this->assertContains(HasApiTokens::class, class_uses_recursive(User::class));
    }
}

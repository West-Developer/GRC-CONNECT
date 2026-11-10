<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route Registration
    |--------------------------------------------------------------------------
    |
    | Disabled deliberately. Sanctum registers a `GET sanctum/csrf-cookie`
    | route by default, which PRD §9.1 explicitly forbids: "Do not use session
    | cookies, CSRF-cookie endpoints, or `withCredentials`." This application
    | authenticates exclusively with bearer personal access tokens.
    | `ApiSurfaceTest` asserts the route inventory and will fail if this is
    | ever re-enabled.
    |
    */

    'routes' => false,

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Intentionally empty. Any domain listed here would receive stateful
    | cookie authentication, which this application does not use. Keeping this
    | empty ensures every request is authenticated by bearer token alone.
    |
    */

    'stateful' => [],

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | Intentionally empty. Sanctum checks these guards before falling back to
    | bearer-token authentication; the stock value is ['web'], which would
    | consult the session guard first. An empty list forces bearer-token
    | authentication on every request, per PRD §9.1.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | PROVISIONAL LOCAL DEFAULT — NOT AN APPROVED INSTITUTIONAL POLICY VALUE.
    |
    | PRD §9.1 requires "an approved expiration policy" and PRD §17 lists the
    | session/token lifetime as an open institutional decision that authorized
    | GRC stakeholders have not yet confirmed. The 480-minute (8 hour) value
    | below is a safe development placeholder chosen only so tokens do not
    | live forever; it must be replaced with the approved value before any
    | production-like deployment. Sanctum's own default (null = never expires)
    | is deliberately not used, so an unset policy fails safe rather than
    | issuing immortal tokens.
    |
    */

    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 480),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | Intentionally empty. The stock entries (authenticate_session,
    | encrypt_cookies, validate_csrf_token) exist to support first-party SPA
    | cookie authentication, which this application does not use.
    |
    */

    'middleware' => [],

];

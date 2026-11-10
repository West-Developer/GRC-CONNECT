# ADR 0003 — Sanctum Bearer Authentication

**Status:** Accepted for Phase 1 implementation  
**Date:** 2026-07-26  
**Decision source:** PRD §6.2, §8, §9.1, and Phase 1

## Context

The React SPA and Laravel API are independently deployable. The PRD explicitly requires Laravel Sanctum personal access tokens sent in the `Authorization` header and explicitly excludes stateful session-cookie authentication.

## Decision

- Laravel Sanctum personal access tokens authenticate protected `/api/v1` requests.
- The SPA's future `auth-token` module will be the only module allowed to read, write, or remove the bearer token from browser storage.
- The shared HTTP client will attach `Authorization: Bearer <token>`.
- Logout will revoke the current token and clear it from the SPA.
- Do not enable Sanctum's stateful SPA middleware, CSRF-cookie endpoints, session cookies, `withCredentials`, or credentialed CORS.
- Policies still enforce role and record authorization after authentication.
- Token lifetime, recovery, and first-login policies remain configuration blockers until authorized GRC approval.

## Consequences

- XSS prevention and Content Security Policy are critical because the approved client stores a bearer token.
- CORS uses an explicit frontend-origin allowlist and disables credentials.
- Missing, expired, and revoked token behavior requires explicit `401` tests.
- This ADR records the architecture only; Phase 0A does not install or expose authentication endpoints.

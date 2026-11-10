# ADR 0008 — Authorization: Role Middleware Plus Policies, Row Filtering in Query Scopes

**Status:** Accepted
**Date:** 2026-07-27

## Context

Through the identity foundation and Sanctum auth slice, every authenticated
route required only a valid, active bearer token — `auth:sanctum` plus
`EnsureUserIsActive`. No route asked *which* of the nine roles the token
belonged to, and no Policy class existed anywhere in the application.

PRD §9.4 requires: "Every resource endpoint has a Policy or explicit
authorization decision," and "validate both role-level and record-level
access." Every acceptance criterion under PRD §5.1 (Establish Pre-Enrollment
Schedules) is an authorization statement — "A Dean cannot approve a schedule
that is not in `draft`," "An Executive Director cannot approve a schedule not
approved by the Dean" — so this had to be resolved before any endpoint in that
process could be built.

Laravel 12 also removed the `AuthorizesRequests` trait from the generated base
`Controller` class. `$this->authorize()` was therefore unusable anywhere in
this codebase until this slice.

## Decision

Use two complementary mechanisms, because neither alone satisfies both halves
of PRD §9.4's requirement:

1. **`EnsureUserHasRole` middleware** (registered as the `role` alias in
   `bootstrap/app.php`) — a coarse, route-level gate. `Route::middleware(['role:dean,executive_director'])`
   answers "can this role reach this endpoint at all," visibly, in the route
   table itself.
2. **Laravel Policies** (`App\Policies\ProgramPolicy`, `AcademicTermPolicy`,
   and future policies following the same shape) — record-level decisions,
   invoked via `$this->authorize()` from `App\Http\Controllers\Controller`,
   which now `use AuthorizesRequests` again.

Row-level **filtering** of a list response is deliberately kept out of both:
a Policy answers yes/no for a single record, and middleware cannot filter a
collection at all. Instead, each model exposes a `scopeVisibleTo(Builder,
User): Builder` query scope (`Program::scopeVisibleTo()`,
`AcademicTerm::scopeVisibleTo()`), which restricts *which rows* a
learner-scoped role receives from a list endpoint. The status enums
(`ProgramStatus`, `AcademicTermStatus`) own the `isVisibleToLearners(): bool`
predicate, so no policy or scope compares status strings directly.

The learner/planner split is a single source of truth:
`UserRole::isLearnerScoped()`. Three roles (`student`, `faculty`,
`accounting_staff`) are learner-scoped; the remaining six see every record
regardless of status.

`AuthorizationException` was already mapped to a 403 `FORBIDDEN` envelope in
`ApiExceptionRenderer` from the auth slice, so no error-handling code was
needed for either mechanism.

## Consequences

- Every future write endpoint under PRD §5.1 (curriculum, sections, schedule
  proposals) follows this same four-part shape: `role` middleware for
  coarse route access, a Policy for per-record decisions, a `visibleTo` scope
  for list filtering, and the role-classification predicate living on
  `UserRole` or the resource's own status enum — never duplicated inline.
- **No production route uses the `role` middleware in this slice.** Both
  `GET /api/v1/programs` and `GET /api/v1/academic-terms` are readable by all
  nine roles; they differ only in which rows come back. The middleware ships
  fully tested (`RoleMiddlewareTest`) against a route registered only inside
  that test, so its first real route-level consumer — the curriculum-catalog
  slice's write endpoints — inherits a proven, not speculative, mechanism.
  This is accepted as intentional scaffolding, not dead code left by accident.
- `ProgramStatus` and `AcademicTermStatus` are **provisional vocabularies**
  (PRD §17 remains open on institutional status values). The visibility rule
  each enum encodes — which statuses a learner may see — is a security-scoping
  decision this ADR makes now; the *specific vocabulary* is not final and must
  migrate via data migration, not a schema change, once GRC confirms it.

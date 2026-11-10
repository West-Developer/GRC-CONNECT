# ADR 0014 — Presentation-Layer State Contract

**Status:** Accepted
**Date:** 2026-07-31

## Context

PRD §12.4 names the states every screen must handle distinctly: loading,
empty, unauthorized, not-found, conflict, throttled, dependency failure
(5xx/offline), and validation. Before Phase 8a, the 19 portal workspaces each
hand-rolled their own loading/error/empty triad — roughly 26 sites total — and
every one of them collapsed every failure into the same generic copy
("X could not be loaded. Refresh and try again."). A grep across `src` for
`403`, `404`, `409`, or `429` returned zero hits; `ApiClientError.kind ===
"connection"` (the offline signal already carried by the API client) was read
nowhere. A cross-role permission denial and a dropped Wi-Fi connection
rendered identical text. Retry logic made this worse: `query-client.ts` set a
blanket `retry: 1`, so a 429 throttle was retried automatically — exactly the
one status where retrying makes the problem worse.

This ADR is the decision record for the fix: one shared mapping from
`ApiClientError` to a user-facing state, applied through one shared component,
so the states named in §12.4 are actually distinguishable in the UI.

## Decision

### 1. One function owns the HTTP-status → presentation mapping

`src/features/lib/api-error-presentation.ts` exports `getStatePresentation(error,
{ onRetry? })`, returning `{ title, message, action? }`. It is the single
place that decides what a failure looks like:

| Condition | Title | Retry action | Notes |
|---|---|---|---|
| not an `ApiClientError` | "Connection interrupted" | yes | defensive fallback for a thrown value the client didn't produce |
| `kind: "connection"` | "Connection interrupted" | yes | offline / fetch could not reach the API |
| `kind: "configuration"` | "API address needs attention" | yes | pass through the client's own message |
| `kind: "contract"` | "Unexpected API response" | yes | pass through the client's own message |
| `status === 403` | "You don't have access" | **no** | retrying an authorization failure never helps |
| `status === 404` | "Not found" | **no** | retrying a missing resource never helps |
| `status === 409` | "Conflict" | yes | the underlying state may have changed; a retry after re-reading can succeed |
| `status === 429` | "Slow down" | **no**, message states the `Retry-After` wait when present | an explicit action would invite immediate re-throttling |
| `status >= 500` | "Something went wrong" (+ `requestId` when present) | yes | transient; also the one case where the request id is surfaced for support |
| any other 4xx | "API check was not accepted" (+ `requestId`) | yes | catch-all for a status this mapping doesn't special-case |

422 is deliberately absent from this table — field-level validation is a
different contract (`applyApiFieldErrors` in `lib/api-form-errors.ts`, wired
to React Hook Form) and was already correct before this slice; this ADR does
not change it, beyond making it focus the first invalid field on submission.

### 2. `AsyncBoundary` is the one place queries render this mapping

`src/features/components/portal/async-boundary.tsx` takes a query-shaped
object (`isPending`, `isError`, `error`, `data`, `refetch`) and renders
exactly one of: a `role="status"` loading region, a `role="alert"` error
built from `getStatePresentation`, the caller's empty message, or the
resolved children. Every workspace's read path (the `AsyncBoundary`-wrapped
`DataTable`/`Paginator` combinations) gets 403/404/409/429/offline handling
for free by construction, not by each file remembering to implement it.

Two workspaces — `EnrollmentWorkspace` and `RegistrarEnrollmentWorkspace` (and
similarly-shaped mutation flows in `RegistrarRecordsWorkspace`) — keep
independent, hand-written `Alert` blocks for their *mutation* failures
(submit / approve / reject / void) instead of routing through
`getStatePresentation`. This is intentional, not a gap: a failed mutation
needs to preserve in-progress form state (the selected section, the typed
reason) exactly as entered, which is a per-workspace concern `AsyncBoundary`
does not own. These paths render one generic, safe message regardless of the
failure's status — they do not yet distinguish a 409 mutation conflict from a
5xx. Read paths (`AsyncBoundary`-wrapped queries) are the only place this ADR
claims status-specific presentation.

### 3. Retries stop guessing on 4xx

`src/features/lib/query-client.ts`'s `shouldRetryQuery` replaces the old
blanket `retry: 1`: it retries at most once, and only for `kind: "connection"`
or `status >= 500`. Every 4xx (particularly 403/404/409/422/429) is
never retried automatically. `AsyncBoundary`'s manual "Try again" button
still lets the user retry a 409 by hand once they believe the conflict is
resolved; the client no longer retries it for them.

### 4. A rejected token drives sign-out through `AuthContext`, not just token storage

Previously, `api-client.ts`'s 401 handler cleared the stored token but left
`AuthContext` unaware, so a user with a revoked session stayed on a stale
portal until a manual reload. `AuthGateway` gained `clearSession()`;
`AuthProvider` now registers the unauthorized handler itself
(`gateway.clearSession(); setSession(null); setStatus("anonymous")`), so a
401 anywhere in the app immediately drops the user back to a signed-out state
through the same React state the rest of the UI already observes.

## Consequences

- Every future workspace that reads data through `AsyncBoundary` gets
  403/404/409/429/offline/5xx presentation without writing its own error
  branch. A new workspace that needs a *different* message for one of these
  statuses is the exception, not the default — that would be a deliberate
  override, not a copy-paste of the old per-file pattern.
- The two-tier split (query errors through `AsyncBoundary`, mutation errors
  hand-written per workspace) is a known asymmetry. If a future slice needs
  status-specific mutation messages (e.g., distinguishing a 409 enrollment
  conflict from a 5xx), `getStatePresentation` already has the mapping;
  wiring it into `EnrollmentWorkspace.submit()` and the registrar decision
  flows is additive, not a redesign.
- `getStatePresentation`'s table is the contract test surface —
  `api-error-presentation.test.ts` asserts every row above at the unit level,
  `async-boundary.test.tsx` asserts the retryable/non-retryable rendering
  distinction, and representative workspaces
  (`registrar-records-workspace.test.tsx` for 403/404/429,
  `enrollment-workspace.test.tsx` for 409 and offline) assert the same
  mapping end-to-end through a real query hook and a real fetch mock, not a
  hand-constructed `ApiClientError`.

## Alternatives considered

**Map status codes inline in each workspace, as before.** Rejected — this is
exactly the state the audit found: 19 files each re-deciding what a 403 means,
with 15 of them never deciding it at all.

**Route mutation errors through `AsyncBoundary` too.** Rejected for this
slice. `AsyncBoundary` replaces a *query's* entire render output (loading,
error, or children) — a mutation failure must not blank out the form the user
was mid-editing. Solving this correctly means threading `getStatePresentation`
into each workspace's own `catch` block while preserving local state, which is
a real but separable follow-up (noted above), not a reason to block this
slice's query-side fix.

**Retry every 4xx once, matching the old behavior for simplicity.** Rejected.
Retrying a 429 is actively harmful (it worsens the throttle), and retrying a
403/404/409 wastes a round-trip on a request that cannot succeed without the
underlying condition changing.

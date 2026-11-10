# ADR 0029 — Performance Optimizations (Loading Speed Checklist)

**Status:** Accepted
**Date:** 2026-09-25
**Amends:** ADR 0015 (in-page loading now defaults to a skeleton; the branded logo stays for full-page and session-restore states) and the polling cadences noted in ADR 0023/0027 (only for staff/list views; the student queue view is unchanged).

## Context

The stakeholders asked for a smoother, faster system and re-supplied a 17-item performance
checklist (images, skeletons, debounce, code splitting, re-renders, minification, deferred
scripts, CDN, N+1, caching, pagination, compression, indexes, connection pooling, unused
dependencies, an ML load balancer, ML caching) and asked which items already existed.

A read-only audit (2026-09-25) found: images and minification were already fine; the React
Compiler and an N+1 guard existed but were uncommitted; and the real cost was elsewhere:

- **Requests**: `GET /sections` ran up to ~630 extra queries per call (the professor was lazy-loaded
  per row) and `GET /enrollments` ran extra queries per row (`isLateEnrollee()` and, per waiting
  ticket, one to three COUNT queries in `QueueTicket::position()`); the lazy-load guard had logged
  244,501 violations into a 203 MB `laravel.log`.
- **Writes on reads**: Sanctum stamped `personal_access_tokens.last_used_at` on every authenticated
  request, so every 5-second poll was a database write.
- **Traffic**: about 84% of requests were polls (bell 5 s, staff lists 5 s, student account 5 s).
- **Frontend weight**: all 48 workspaces, with the charts, shipped as one ~480 KB gzipped route chunk;
  five search boxes queried the server on every keystroke; 73 `AsyncBoundary` call sites showed a
  spinner-like logo instead of a page-shaped placeholder; paging or filtering blanked the table.
- **Serving**: `php artisan serve` (single process, no OPcache) and `next dev` (unminified, compiles on
  demand) are what the launcher ran day to day.

## Decisions

**1. Fix the query counts at the source.** `SectionController` eager-loads `professor`;
`ListEnrollments` eager-loads `academicTerm.enrollmentWindows`; `ConfirmPayment` eager-loads every
relation its response reads; the lazy-load handler logs each `(model, relation)` once instead of once
per violation; `QueueTicket::preloadPositions()` computes a whole page's queue positions from one
query and is proven equal to `position()` by a parity test (priority tier, carry-over date, requeue
moment, ties, second cycle). Query-count regression tests pin `GET /sections`, the grades list and the
position preload, so these cannot silently return.

**2. Index the hot filters.** One reversible migration adds `enrollments(academic_term_id, status,
submitted_at)`, `queue_tickets(queue_cycle_id, status, priority)`, `enrollment_subjects(section_id,
status)` and `academic_grades(academic_term_id, status)`. `ListQueueTickets` compares `queue_date`
directly (after normalising to `Y-m-d`) instead of `whereDate()`, whose `DATE()` wrapper defeats an
index. MariaDB 10.4 has no descending indexes; the first index is read backwards for
`ORDER BY submitted_at DESC`. Every one of these indexes leads with a foreign-key column, so creating
it makes InnoDB drop that FK's implicit index; `down()` therefore re-creates a plain index on the FK
column before dropping the composite (otherwise MariaDB answers `1553 ... needed in a foreign key
constraint` and the rollback stops half-way). Verified with fresh → rollback → migrate → rollback.

**3. Stop writing on every read.** `App\Models\PersonalAccessToken` (bound with
`Sanctum::usePersonalAccessTokenModel`) skips a `last_used_at` stamp when the last one is under five
minutes old. Nothing in the app reads `last_used_at` (expiry uses `expires_at`). The class must stay
non-`final`: `Sanctum::actingAs()` mocks whatever token model is configured and Mockery cannot mock a
final class, so a `final` here breaks every test that authenticates that way.

**4. Poll less, and only while visible.** Notification bell 30 s, role-scoped enrollment lists 15 s,
queue cycle 15 s, student account 15 s, schedule proposals 30 s, all with
`refetchIntervalInBackground: false`. Unchanged on purpose: the student's own queue view (3 s, and it
keeps polling in a background tab so the "you are being called" alert still fires, ADR 0023/0027), the
cashier's live queue (5 s), and the student's own enrollment (10 s).

**5. Compress the JSON.** `CompressJsonResponse` gzips `JsonResponse` bodies over 1 KB for clients that
send `Accept-Encoding: gzip` and never re-encodes a response that already has a `Content-Encoding`;
PDFs and errors are untouched. `API_COMPRESS_JSON=false` switches it off when a reverse proxy compresses
instead. HTTP caching of API responses is deliberately NOT introduced: every private GET keeps
`Cache-Control: no-store, private` (records privacy).

**6. Load only what the page needs.** Every workspace is a `next/dynamic` chunk through one helper
(`lazyWorkspace` in `module-registry.tsx`), which also records the loader so
`preloadConnectedModules()` can warm all chunks (tests use it; a hover prefetch could too). Charts load
only with the analytics workspaces. The fallback is a page-shaped skeleton.

**7. Skeletons by default, previous data while paging, debounce.** `AsyncBoundary` renders a
heading-and-cards skeleton (announced once as a status region) when a caller supplies no
`loadingFallback`; the branded `GrcLoadingLogo` remains for the full-page and session-restore states and
is now the `<Suspense>` fallback of the portal layout. Filtered and paginated list hooks use
`keepPreviousForSameUser(userId)` as `placeholderData`: the previous page stays on screen while the next
loads, but only within the same `session.userId` (private query keys are `[name, userId, filters]`, so
one user's rows are never shown to another). Search boxes that queried the server per keystroke
(Registrar enrollments and records, Graduates, Program Chair credit mappings, Cashier COR records) use
`useDebouncedValue(…, 300)`.

**8. The ML bridge.** The FastAPI prediction handlers are plain `def` so the CPU-bound `fit()` runs in
the thread pool and no longer blocks `/health`. The Laravel clients send `X-Request-ID`, use
`connectTimeout(2)` and `retry(2, 200)`, and cache a successful response for one hour keyed by
`sha256(feature schema version + canonical payload)` (deterministic model, aggregate data only); a failed
call is never cached. Unused `pandas` is dropped from `ml-service/requirements.txt`.

**9. Run the real bundle when it matters.** `scripts/start-local.ps1 -Production` builds once and serves
`next start`. `NEXT_DIST_DIR` lets a build write outside `.next`, so it cannot clobber a running
`next dev`.

## Update 2026-10-04 — Regular-student section picker polls at 3 s

The stakeholders asked that a Regular student see the open seats of their section drop in real time and that
only ONE section (the first with seats) be offered at a time, the next appearing when it fills. The
`GET /enrollment-blocks` query that feeds that picker (`useEnrollmentBlocksQuery`) therefore moves from 10 s
to **3 s** (`ENROLLMENT_BLOCKS_POLL_MS`), the same tier as the student's own queue view. It still pauses in a
background tab (`refetchIntervalInBackground: false`) and re-reads on window focus, and it only runs for a
Regular student who is on the Enrollment page with no active enrollment this term. It is still polling, not
push (no WebSocket/SSE, as in ADR 0023/0027). This endpoint runs `BuildEnrollmentBlockPool`, which is heavier
than the queue view, so watch its request rate at enrollment peaks; if it proves costly, raise the constant
(5 s is the next sensible step) rather than add push infrastructure.

## Not done, and why

- **Server-side cache of reference lists** (`/academic-terms`, `/programs`, `/subjects`, `/curricula`,
  `/room-options`, `/fee-schedules`). Each is one indexed query; the heaviest (`/curricula`, with its
  placements) has many write paths (curriculum editor autosave, migrations, approvals), so a cache would
  risk stale curricula for a small gain. Revisit with model-event invalidation if measurement shows it.
- **`/notifications/unread-count` endpoint.** At a 30 s poll the existing `per_page=1` list is cheap.
- **`next/font` and an API `preconnect`.** Fonts are self-hosted CSS imports (`font-display: swap`); the
  API base URL is rewritten at runtime for phones on the LAN, so a server-rendered preconnect would point
  at the wrong origin. Both belong to a production deployment with a fixed origin.
- **Removing `laravel/sail`.** Dev-only; no runtime cost; would churn the lockfile.
- **Connection pooling, an ML load balancer, a CDN.** PHP is per-request and there is one ML replica and no
  deployment target in the repository. Ready-to-use configuration is in
  `docs/runbooks/performance-deployment.md`; nothing was installed or changed on the machine.
- **Notification retention / pruning.** Needs an institutional retention decision (PRD §17).
- **Rendering the shell from a cached identity before `/auth/me` returns.** Touches authentication; not
  attempted in a performance pass.

## Verification

Backend: the new and updated tests (token throttle 5, gzip 6, queue-position parity, sections query
count, confirm-payment eager loads) and the suites around them pass on an isolated MariaDB; Pint and PHPStan
are clean on the touched files. Frontend: `tsc`, ESLint and the affected Vitest suites pass. Not measured:
a production bundle size (a build would overwrite the running dev server's `.next`); use
`NEXT_DIST_DIR=.next-perf npx next build` when the machine has memory to spare.

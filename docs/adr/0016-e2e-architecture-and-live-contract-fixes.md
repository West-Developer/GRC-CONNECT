# ADR 0016 — E2E Architecture, and Contract Bugs Only a Real Browser Caught

**Status:** Accepted
**Date:** 2026-07-31

## Context

`e2e/` was pre-reserved since the project's early scaffolding — `.gitignore`,
`README.md`, `PRD.md` §558, and `docs/architecture/version-compatibility.md`
all already assumed it — but empty until Phase 8c. This ADR records how it
was filled, and, more importantly, three genuine defects a real browser
running against the real stack surfaced that no prior test layer (backend
PHPUnit, frontend Vitest with mocked `fetch`) could have caught, because
each of those layers tests one side of a contract in isolation.

## Decisions

### 1. `e2e/` is its own root-level npm package

Settled by the pre-existing `/e2e/node_modules/` `.gitignore` entry, not
invented here. `@playwright/test` ^1.62.0 and `@axe-core/playwright`
^4.10.2, matching `version-compatibility.md`'s already-recorded pin.

### 2. Run-scoped `migrate:fresh --seed`, not per-test reset

One reset before the whole suite runs, via `e2e/scripts/reset-db.mjs`
(`php artisan migrate:fresh --seed --env=testing --force`) — not before
each test. `DatabaseSeeder`'s full chain (9 role identities, 3 student
lifecycle scenarios, programs/terms/subjects/curricula/sections) is already
idempotent and gives every journey's precondition-arrangement code a known
starting point.

**On the documented MariaDB crash history**: `docs/runbooks/mariadb-local.md`
records two prior crashes on this workstation, one of them reproducibly
triggered by a schema-wildcard `GRANT ... ON db.* TO ...` statement. This
was initially treated as a reason to fear any repeated schema operation
against this MariaDB install. It isn't — `migrate:fresh` issues only DDL
against a database already dedicated to testing, never a `GRANT`, and is the
exact reset path `composer test`'s 641 tests already exercise on every run.
**The rule this suite has to honor is "no schema-wildcard `GRANT`," not "no
migrations."**

### 3. API-arranged preconditions — and they must be genuinely self-contained

Journeys with a causally-dependent precondition (registrar approval needs a
submitted enrollment; payment confirmation needs an approved one) arrange
that precondition over the API rather than replaying another journey's UI.
This was already the plan going in. What wasn't anticipated, and had to be
learned by hitting it directly:

**A precondition arrangement that only checks "does the seeded state already
satisfy this?" and falls back to nothing is not actually self-contained.**
`journey 8`'s first implementation looked for `student3.seed@grc.test`
already sitting in `pending_payment` (true on a fresh seed) — and failed the
moment it was re-run, because the *previous* run had already consumed that
exact state. The same thing happened independently to journey 7 against
`student2.seed@grc.test`. The fix in both cases: check for existing usable
state first, and when none exists, **submit a fresh enrollment from
scratch** (a student whose seeded enrollment is a terminal status is
eligible to submit again) rather than assuming the seed's one-time shape is
always available. This is the same principle Playwright's own "tests should
not depend on execution order or on each other" guidance states, just
learned against a real, stateful backend rather than a synthetic example —
seeded fixture data is not exempt from it.

### 4. Journey 12 (throttle) runs in its own isolated project

`routes/api.php:39` keys the login rate limiter (`throttle:30,1`) **per IP**,
not per credential. Every Playwright worker shares one source IP, so a
tripped limiter blocks every other journey's sign-in for the rest of that
window. `playwright.config.ts` isolates `validation-and-throttling.spec.ts`
into a `throttle-isolated` project (`fullyParallel: false`, `workers: 1`,
declared as depending on the main `chromium` project so it always runs
last).

### 5. `CACHE_STORE=array` silently disables the rate limiter over real HTTP

The most consequential infrastructure finding. `backend/.env.testing` had
`CACHE_STORE=array` — correct for PHPUnit, where an entire test runs inside
one PHP process and the array driver's in-memory state persists across
simulated "requests" within it. It is **wrong** for `php artisan serve`,
because PHP's built-in development server spawns a fresh PHP process for
every real HTTP request; the array driver's state does not survive between
them. `RateLimiter` uses Laravel's default cache store
(`config/cache.php`'s `'default' => env('CACHE_STORE', 'database')`, no
dedicated `limiter` store) — so with `CACHE_STORE=array`, 31 rapid login
attempts over real HTTP never trip `throttle:30,1` at all; each request
starts counting from zero.

Fixed by changing `.env.testing`/`.env.testing.example`'s `CACHE_STORE` to
`file`. This does **not** affect PHPUnit: `backend/phpunit.xml` sets
`<env name="CACHE_STORE" value="array"/>` directly, and a real process
environment variable always wins over a `.env` file value in Laravel's
Dotenv loading (`safeLoad()` never overwrites an already-set variable) — so
PHPUnit's 641 tests are provably unaffected (confirmed by re-running the
full suite after the change: still 641 passed / 2,419 assertions).

**A related, discarded approach** worth recording so it isn't retried: a
separate `--env=e2e` file (`.env.e2e`, `APP_ENV=e2e`) was tried first, on
the theory that a distinctly-named environment would be cleaner. It doesn't
work, for a subtle reason: `--env` only controls which file `artisan
serve`'s *own* bootstrap loads. Each *per-request* child process resolves
its own env file from the `APP_ENV` *value* that gets passed through to it
(`ServeCommand`'s passthrough whitelist includes `APP_ENV`), not from the
original `--env` flag. So a file named `.env.e2e` whose own `APP_ENV` says
`e2e` would need every environment-restricted seeder's `guardEnvironment()`
(currently allowlisting only `local`/`testing`) extended to accept `e2e`
too — a real security-control change, and unnecessary, since `.env.testing`
with a single value fixed is sufficient and changes nothing else.

**A second, unrelated operational trap this surfaced**: `php artisan test`
and the E2E suite both resolve `DB_DATABASE=grc_enrollment_test` from the
same `.env.testing`. Running the full PHPUnit suite *after* an E2E run,
against the same live database, wipes every row the E2E seeding put there
(RefreshDatabase's initial migration reset, not per-test transactions,
which do roll back). There is no per-test corruption risk — but running
PHPUnit and the E2E suite back-to-back locally, in either order, requires
re-running `npm run reset-db` before continuing E2E work. The GitHub CI job
(Task 5) does not hit this: the `e2e` job is independent of the `backend`
job and each gets its own fresh service container.

### 6. `--env=testing` correctly routes `artisan serve` to the test database

Verified empirically, not assumed: a throwaway server on a spare port,
`--env=testing`, correctly served `grc_enrollment_test` (login against
seeded credentials 401'd while that database was empty, 200'd against the
main dev server on the same credentials at the same moment, then 200'd
against the test-env server once seeded, with a different row id than the
dev database's). Mechanism: `--env=testing` makes `artisan serve`'s own
bootstrap load `.env.testing` via Dotenv, which sets `APP_ENV=testing` as a
real process environment variable; that gets forwarded to the spawned
per-request child process (via `ServeCommand`'s passthrough whitelist), so
each request's fresh Laravel bootstrap resolves the same file.

### 7. Two genuine, previously-invisible date-serialization bugs, fixed

`GET /api/v1/enrollments` (and every other endpoint an authenticated
browser session actually rendered) failed the frontend's own Zod contract
the moment a real, non-null timestamp appeared in the response — surfaced as
"Unexpected API response" on the very first journey that rendered a real
enrollment row. Root cause: 7 of 11 date-serializing API Resources
(`EnrollmentResource`, `EnrollmentDocumentResource`, `AcademicGradeResource`,
`PaymentConfirmationResource`, `QueueTicketResource`,
`TransfereeCreditResource`, `WithdrawalRequestResource`) used Carbon's
`->toIso8601String()`, which emits an offset suffix (`+00:00`). The
frontend's `z.iso.datetime()` schemas (used in 10 schema files) accept only
the `Z`-suffixed form by default. Four other Resources
(`AuditLogResource`, `NotificationResource`, `AcademicTermResource`,
`ScheduleProposalResource`) already used the correct, established
convention — `->utc()->format('Y-m-d\TH:i:s\Z')` — so this was an
inconsistency against the codebase's own precedent, not an ambiguous design
question. Fixed by aligning all 7 to the same convention. No backend test
encoded the old format as expected (confirmed: 641/641 still pass), and no
frontend Vitest test caught it because every mocked fixture was hand-written
to already satisfy the schema — a mock, by construction, cannot expose a gap
between what the mock's author assumed and what the real serializer emits.

**This is the central justification for E2E testing existing in this
project at all**: PHPUnit verifies the backend's output against its own
expectations; Vitest verifies the frontend's rendering against hand-written
fixtures; neither one, by construction, can catch the seam between them
being subtly wrong. Only running the real frontend against the real backend
does.

### 8. One real UI gap, and one claim in this ADR that was itself wrong

**Correction (2026-07-31, Phase 7c):** this decision originally claimed
`ScheduleDecisionWorkspace` had no reachable module id for
`executive_director`, and that journey 5's Executive Director half had to be
tested over the API as a result. That claim was wrong. The Executive
Director's approval controls *are* reachable — through `master-schedule`,
which *is* in their module list. `MasterScheduleWorkspace` embeds
`ScheduleDecisionControls` with `actorRole="executive_director"`, and
`legalActions` in `scheduling-service.ts` grants that role `executive_approve`
on `dean_approved` proposals. The E2E journey navigated to the wrong module
id (`schedule-approvals`, the Dean's own workspace) found nothing, and the
miss got written up as a missing feature rather than traced further. Journey
5 now drives the real `/portal/master-schedule` UI for the Executive
Director's half, exactly like every other journey.

**What tracing it further actually found — a real bug**: `MasterScheduleWorkspace`
wrapped *both* the published-sections list and the `ScheduleDecisionControls`
card in one `AsyncBoundary`, gated on `published.length === 0`. With no
section published yet, the Executive Director saw only "No published
sections are available" — and had no way to approve the very first proposal,
which is precisely the action that publishes the very first section. Fixed
by splitting into two independent `AsyncBoundary`s: the sections list stays
gated on section emptiness; the decision controls are gated only on the
proposals query, and `ScheduleDecisionControls` already self-handles zero
eligible proposals ("No schedule decisions are currently available."). A
regression test (`master-schedule-workspace.test.tsx`) covers the empty-
sections-but-approvable-proposal case directly.

**No student-facing "Withdraw" button exists** — this claim was
re-verified and stands. `useCreateWithdrawalRequestMutation` is fully
implemented and exported but called from zero components.
`POST /enrollments/{id}/withdraw` is genuinely student-initiated per PRD
§4.2 rule 7, and fully idempotency-guarded server-side
(`StoreWithdrawalRequestRequest` rejects a second pending request with a
clear 422) — journey 13 exercises that guard over the API, then verifies the
*observable* outcome (exactly one row, not two) through the one piece of UI
that does exist: Registrar Staff's Drops & Withdrawals queue. Recorded as a
real, honest gap — the same treatment journey #14
(skipped, ml-service dormant) and journey #15 (partial, no report content
yet) already got, not silently patched over with invented UI or silently
left unexplained.

### 9. Deferred and partial journeys

- **#14 (prediction-service failure)** — `test.skip()`, documented: the
  ml-service is dormant, none of the 48 live routes are prediction routes,
  and `AGENTS.md` forbids touching `ml-service` before Phase 9.
- **#15 (compliance report authorization)** — partial. `compliance-reports`
  is a registered but placeholder module (Phase 7c, blocked on
  institutional content). The authorization half (non-Registrar-Head roles
  denied) is real and tested; the report content does not exist.

## Consequences

- A future journey needing "arrange a precondition that a fixed seed
  normally provides" must default to the self-contained submit-fresh
  pattern (decision 3), not a single seeded-state lookup — the suite is
  meant to be re-run repeatedly against the same database between resets,
  including by a developer iterating locally.
- `CACHE_STORE=file` in `.env.testing` is now load-bearing for the E2E
  suite specifically — changing it back to `array` would silently disable
  journey 12 again without any test failure pointing at the reason.
- Running `php artisan test` and the E2E suite locally, in either order,
  requires re-seeding (`npm run reset-db`) before continuing with whichever
  runs second — a real, if minor, workflow cost of sharing one database
  between them, accepted rather than standing up a second isolated database
  for this phase.
- The date-format fix (decision 7) generalizes: **any new Resource must use
  `->utc()->format('Y-m-d\TH:i:s\Z')` for timestamps, never
  `->toIso8601String()` or bare `->toJSON()`.**
- The missing Withdraw button (decision 8) is now tracked, not silently
  reintroduced by a future session assuming it was never noticed. The
  Executive Director schedule-approval "gap" was not real — but the
  empty-state bug it led to tracing down was, and is fixed (Phase 7c).
- **A miss this ADR's own history is worth internalizing**: a claim written
  here without re-tracing why a UI element wasn't where expected got treated
  as settled fact for a full session, including a corrected commit message.
  When an E2E journey can't find something, the next step is tracing the
  component tree (is it rendered somewhere else under a different route?),
  not concluding the feature is unbuilt.

## Alternatives considered

**Per-test database reset (`migrate:fresh` before every test).** Rejected —
13+ full migration cycles per run against a MariaDB install with a real
crash history, for no correctness benefit `DatabaseSeeder`'s idempotency and
self-contained precondition arrangement don't already provide.

**A dedicated `.env.e2e` with its own `APP_ENV=e2e` value.** Rejected — see
decision 5; doesn't work for per-request child processes without also
extending every environment-restricted seeder's allowlist, which is a real
security-control change this phase has no reason to make.

**A second isolated database for E2E, separate from PHPUnit's
`grc_enrollment_test`.** Rejected for now — real added setup complexity
(a second CI service container, a second local database) to avoid a
workflow cost (re-seed after running the other suite) that's cheap to pay
manually and does not affect CI, where the two suites run in fully
independent jobs with fully independent database containers.

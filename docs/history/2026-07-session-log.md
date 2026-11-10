# Session History Archive — July 2026

Detailed working history for the GRC Enrollment System, covering the sessions
from **2026-07-26** (repository inception) through **2026-07-28** (Student
Profile Foundation merged and CI-confirmed).

This content was moved verbatim out of `PROGRESS.md` on 2026-07-28 when that
file was restructured into a living tracker. Nothing was edited, condensed, or
removed in the move — only relocated. `PROGRESS.md` now carries the completion
tracker, the phase roadmap, the portal matrix, and a summary table that links
back here.

Read this file when you need the *why* behind a decision, the full timeline of
an incident, or the exact verification output of a merged slice. Read
`PROGRESS.md` when you need to know where the project stands today.

---

## Failure and Recovery Record

- The first canonical-file patch was invalid, and the patch move then removed
  the redundant PRD copy; it was restored from the hash-verified canonical file.
- Initial runtime lookup could not find `mysql` on `PATH`; the explicit XAMPP
  audit found MariaDB 10.4.32 instead.
- The initial Laravel scaffold dependency lookup and several early Composer
  audits timed out against Packagist. Dependency installation recovered, and
  the final explicit locked audit passed with no advisories.
- Initial strict Composer validation rejected an exact framework constraint
  style; the supported `^12.64` constraint passes.
- An initial backend CORS test expected an absent header for a single configured
  origin. Live diagnosis showed the framework emits a fixed non-matching origin;
  coverage was corrected and later expanded to a two-origin allowlist,
  preflight, and rejected-origin behavior.
- The first ML Ruff run found import-order/format changes, and the original
  TestClient dependency emitted an upstream deprecation warning. The code was
  formatted and the current official test client installed; final checks pass.
- The first OpenAPI parse command had invalid PowerShell quoting. Two Redocly
  iterations then exposed missing public-security, 4xx, server, and license
  metadata. The final document parses and lints without warnings.
- The first frontend audit found a high React Router RSC advisory and a moderate
  transitive shadcn CLI advisory. Both unused runtime packages were removed;
  the final audit reports zero vulnerabilities.
- The first TypeScript 6 check rejected deprecated `baseUrl`; path aliases were
  retained without it and strict checking passes.
- Browser selection failed because no browser was available. Required
  troubleshooting confirmed the browser list was empty; no fallback browser
  claim was made.
- Final route inventory unexpectedly exposed generated local storage serve/upload
  routes. `filesystems.disks.local.serve` was disabled, and the rerun shows only
  the health route.
- The first listener-cleanup verification command had a PowerShell pipeline
  syntax error; the corrected command confirmed ports 5173, 8000, and 8100 were
  no longer listening.
- The first isolated Python-audit cleanup script was rejected before execution
  by command policy, and `pipx` was unavailable. A separate temporary venv
  completed the audit. Recursive cleanup of that verified temporary directory
  was also blocked, so the non-project tool environment remains under the user
  temp directory at `grc-pip-audit-20260726-1719`; no workspace file is affected.
- The first combined design-spec/progress patch failed atomically because one
  expected `PROGRESS.md` line did not match its wrapped form. No partial file was
  created; the specification and progress changes were reapplied as smaller
  exact patches.
- The first Task 2 source-review `rg` expression used lookahead without
  `--pcre2`; the corrected scan ran with PCRE2 and found no forbidden import,
  remote asset, or RSC directive after review.
- The isolated worktree's first format check flagged 44 files because global
  Git `core.autocrlf=true` checked tracked files out as CRLF while Prettier
  requires LF. Byte/line-ending evidence confirmed the root cause.
  `.gitattributes` now fixes the repository policy at LF (PDF remains binary);
  formatting was normalized and the rerun passes.
- The upstream generated Field source used `Array<T>`, which the repository
  ESLint policy forbids. It was changed to the equivalent `T[]`; ESLint and all
  other Task 2 checks now pass. Existing Button and Separator sources were not
  overwritten.
- The first credential-document test used `new URL(..., import.meta.url)`;
  Vite rewrote the static asset URL, so Node rejected the resulting non-file
  scheme. A raw import was also correctly denied because the document is above
  Vite's frontend root. The test now uses Node file access with a file-local
  Node type reference, preserving the browser project's restricted global type
  set; focused tests and typecheck pass.
- Running Vitest, TypeScript, and Prettier concurrently in the Windows worktree
  starved Vitest's two fork workers until their startup timeout. The same tests
  pass in 1.90 seconds when checks run sequentially, so resource-sensitive
  frontend checks will remain sequential for this worktree.
- Task 4's first ESLint run found seven integration-policy issues: unbound
  method destructuring in the probe, synchronous effect state updates, a
  component/hook Fast Refresh split, `async` functions without `await`, and an
  unused storage initialization. The provider now restores in a cancellable
  microtask, context/hook exports are separated, promise boundaries are
  explicit, and the browser store returns directly; focused tests and both
  linters pass.
- Strict TypeScript then rejected the gateway error's constructor parameter
  property under `erasableSyntaxOnly`. It now uses an explicit declared field
  and assignment; typecheck passes without relaxing configuration.
- Task 5's first lint pass rejected a control-character regular expression and
  mixed component/helper Fast Refresh files. The parser now checks Unicode code
  points explicitly, and the route location probe is isolated in its own
  component file; route tests and both linters pass.
- Task 7 tests found that React Hook Form's default field autofocus overrode the
  required error-summary focus and that the anonymous-only guard could redirect
  to `/portal` before the login page applied a safe module return path. The form
  now focuses its committed summary in an effect, and the guard uses the same
  safe return parser; all login and router tests pass.
- Task 8 tests found that clearing the demo session synchronously could let the
  protected-route guard redirect to login before the explicit home navigation
  settled. The sign-out handler now awaits navigation before clearing session
  state; its route and persistence assertions pass.
- Task 8's first quality command used the wrong local Oxlint script name
  (`lint:oxlint` instead of `lint:fast`) and the format check found two newly
  added test files. No application defect was hidden; the files are being
  normalized and the exact repository scripts will be used for the rerun.
- Task 9's first unknown-module assertion scanned the entire test document and
  saw the route only because the test-only location probe intentionally prints
  the current URL. The assertion now scopes the no-leak check to the rendered
  unavailable-module region; all 52 focused tests pass.
- Task 10's first raw-fetch scan passed an over-escaped regular expression to
  PowerShell and `rg` rejected the unclosed group. A fixed-string scan exposed
  one intentional `refetch()` name plus the raw call; a reviewed PCRE2
  identifier-boundary rerun confirms the sole raw browser `fetch(` remains in
  `src/app/services/api-client.ts`.
- The first post-merge `npm ci --ignore-scripts` on `main` stopped with Windows
  `EPERM` while replacing Rolldown's native binding. This indicates the existing
  binary is open by a local process. Two exact workspace Vite PIDs were found on
  ports 5173/5174, stopped, and both ports confirmed closed. The clean-install
  retry then passed.
- A post-merge command orchestrator yielded partial Vitest/build output without
  completion status and advanced conservatively. Those partial results were
  discarded; no related processes remained, and direct isolated reruns
  completed with 140/140 tests and a successful production build.
- The requested Playwright browser validation was attempted against the active
  local login URL, but the supported MCP browser runtime again returned “No
  browser is available.” This remains a tooling gap rather than an application
  pass; focused browser-like component/router tests are being run, and live
  Playwright claims remain withheld.
- After pushing the curriculum-catalog merge, `git status` showed 43 untracked
  files plus a modified `DatabaseSeeder.php` — none of it with any git
  history (checked `git log --all`, `git stash list`, and `git reflog`; all
  came up empty for these files). A full read-through found genuinely careful,
  convention-matching code (13 migrations/models across faculty input, section
  planning, approval workflow, and a separate enrollment-records domain), but
  it had never migrated cleanly: a `composer test` run mid-investigation hit
  `SQLSTATE[HY000] [2002] ... target machine actively refused it` for 7 of 14
  tests. Checked the Windows Event Log before concluding anything — no fresh
  `mysqld.exe` crash, still only the two known 2026-07-27 incidents — so the
  refusal was a transient connectivity gap, not a new crash. Separately
  discovered the dev database's `migrations` table already listed all 13 new
  tables as applied (batch 3) even though the files were never committed —
  `SHOW CREATE TABLE` confirmed the live schema, including the
  `enrollments.active_academic_term_id` generated column, matches the
  migration files exactly. Resolved by user decision (see Decisions and
  Assumptions) rather than guessing: adopt the code, land the schema as
  tested/documented groundwork, keep the enrollment-records domain schema-only,
  and build the remaining §5.1 sub-projects' API layer through the normal
  branch-per-slice process.
- A `docs/api/openapi.yaml` edit for the faculty-input schemas matched its
  `old_string` one property short of the actual file content, splitting the
  pre-existing `CurriculumSubjectPrerequisiteInput.minimum_grade` property's
  `type: string` from its `maxLength: 255` sibling — the latter got stranded,
  badly indented, at the very end of the file. `@redocly/cli lint` caught it
  immediately as a YAML parse error before any completion claim; fixed by
  restoring `maxLength: 255` next to `type: string` and deleting the
  orphaned line, then re-linting clean.
- While starting a `php artisan serve` for live verification, found a
  pre-existing listener already on port 8000 (two `php.exe` processes,
  started ~40 minutes before this check) that this session never started —
  a possible concurrent process working against the same repository/database.
  Identified my own new pair by matching process start times exactly, stopped
  only those two, and left the pre-existing pair (and one other unrelated
  `php.exe`) untouched rather than guessing at ownership. Relied on the
  automated test suite's full HTTP-level coverage (real Sanctum tokens
  through `postJson`/`patchJson`/`deleteJson` against the real named routes)
  instead of further manual `curl` verification for this slice.
- A new `SectionPolicyTest` test called its own `makeUser(UserRole::ProgramChair)`
  helper twice in one test method; the helper derives each user's email from
  the role alone (`$role->value.'@grc.test'`), so the second call hit
  `users_email_unique`. Fixed by creating the Program Chair once and reusing
  it for both the `create()` and `update()` assertions — the two calls never
  needed distinct users in the first place.
- `SectionConflictDetector`/`ScheduleDayParser` and the two Section Form
  Requests hit the same two Larastan patterns already seen this session:
  (1) a nullable array-shape field (`schedule_days: ?string`) passed to a
  `string`-typed parameter after a null-check in a *different* method —
  PHPStan doesn't carry flow-sensitive narrowing across a method-call
  boundary, fixed by making `ScheduleDayParser::parse()` itself accept
  `?string` and return `[]` for `null`; (2) the same `Collection::map()->all()`
  vs `list<...>` gap the curriculum-catalog slice hit, fixed the same way
  (`array_values(...)` wrapping the whole chain).
- Mid-way through this task's first full test run, `mysqld.exe` stopped
  entirely (confirmed via `tasklist` and `netstat` — no process, nothing on
  port 3306) with **no** crash logged in the Windows Event Log, so this was a
  clean stop by something else, not a third `VCRUNTIME140.dll` incident. The
  symptom was misleading: `composer test` ran for 300+ seconds (versus its
  usual ~20–25s) before Composer's own process-timeout killed it, and the
  piped `| tail` made the reported exit code 0 regardless — the real failure
  was buried in a wall of unrelated `LoginEndpointTest` failures partway
  through the run. Paused and asked rather than starting the service myself,
  given this instance's crash history and every prior restart in this
  project having gone through the user via the XAMPP Control Panel; user
  confirmed they had started it, and a clean rerun completed normally in
  ~23s with everything green.
- `ProgramResource`/`AcademicTermResource` initially defined `withResponse()`
  to set `Cache-Control: no-store, private`, following `UserResource`'s
  pattern exactly — but the header assertion in both new endpoint tests failed
  with `no-cache, private` instead. Root cause: `withResponse()` on an item
  Resource is only invoked when that Resource is returned standalone;
  `JsonResource::collection()` wraps items in an `AnonymousResourceCollection`,
  and `ResourceResponse::toResponse()` calls `withResponse()` on the
  *collection* object, not each item, so the override was silently dead code
  and Symfony's Response computed its own conservative default. Fixed by
  setting the header explicitly on the `JsonResponse` returned from
  `->response($request)` in each controller, and removed the now-misleading
  `withResponse()` overrides from both Resource classes.
- The live HTTP authorization proof for this slice used plain `curl` (no
  `Accept: application/json` header) against `GET /api/v1/programs` and got a
  500 instead of the expected 401. Laravel's `ApplicationBuilder` always
  registers `redirectGuestsTo(fn () => route('login'))` before the app's own
  middleware callback runs; this app is JSON-only and has no `login` named
  route, so any guarded route hit by a client that doesn't send that header
  crashed with `RouteNotFoundException`. This affected every pre-existing
  `auth:sanctum` route too (`/api/v1/auth/me`, `/api/v1/auth/logout`) — no
  prior feature test caught it because `getJson()`/`postJson()` set the
  header automatically. Fixed with
  `$middleware->redirectGuestsTo(fn () => null)` in `bootstrap/app.php`, and
  confirmed by temporarily reverting the fix and re-running the new
  regression test, which failed with the identical error, then passed once
  restored.
- The curriculum-catalog live HTTP proof hit a real gap the automated test
  suite could not have caught: `GET /api/v1/subjects` returned a 500 against
  the live dev server (`grc_app` connection) even though every automated
  feature test passed (those run against `grc_test` via `.env.testing`).
  `storage/logs/laravel.log` showed `SQLSTATE[42000] ... SELECT command
  denied to user 'grc_app'@'localhost' for table
  grc_enrollment.subjects`. Root cause: Task 1 granted `CREATE`+DDL+DML on
  the four new tables to `grc_migrator` and `grc_test` (needed to run and
  test the migrations) but never came back to grant `grc_app` — the
  connection Laravel's own runtime queries actually use — its DML-only
  grant, unlike the identity-foundation slice, which granted `grc_app` right
  after its migrations. Health-checked `mysql.db`/`global_priv`/
  `tables_priv` clean, then granted `SELECT, INSERT, UPDATE, DELETE` to
  `grc_app` on all four tables (table-level, per the MariaDB-instability
  memory); server survived, endpoint immediately returned `200`.
- The approval-workflow endpoint test originally chained four different
  authenticated users (Program Chair submits, Dean approves, Executive
  Director approves and publishes, Registrar Head closes) within one test
  method using sequential `withToken()` swaps. The second swap silently
  failed: `ScheduleProposalPolicy::approveAsDean()` received the *first*
  request's Program Chair user, not the Dean the test had just authenticated
  as, producing a 403 that looked like an authorization bug. Direct dumps
  inside the Policy method and the controller confirmed `auth()->user()`
  itself returned the stale user — this is a genuine Laravel/Sanctum testing
  quirk (the guard resolves and caches a user once per guard instance, which
  outlives a single simulated request within one test method) not previously
  triggered because no earlier test in this codebase had chained multiple
  *different* authenticated users within one test method.
  `$this->app['auth']->forgetGuards()` between swaps did not fix it. Fixed
  by restructuring into four single-actor tests (each precreating the
  proposal directly at whatever status the transition under test requires),
  matching the structure every other endpoint test in this suite already
  uses. See ADR 0011's testing note and Decisions and Assumptions.
- Two of that same test file's setup bugs, caught by the run rather than
  assumed correct: a proposal precreated in `dean_approved` status was
  tested against the `executive_return` action, which actually requires
  `executive_approved` — fixed by correcting the precondition status, not
  the action; and a visibility test called its own `makeTerm()` helper
  twice per test, hitting `academic_terms`' unique `(school_year, semester)`
  constraint — fixed by creating the term once and reusing its ID for both
  proposals (direct `ScheduleProposal::create()` calls don't enforce the
  one-active-proposal-per-term rule, so this was always safe to do).
- The same Sanctum guard-caching quirk recurred a second time in
  `StudentProfilesEndpointTest::test_a_student_can_read_their_own_profile`,
  which had chained an Admission Staff HTTP provisioning call and then a
  different student's HTTP login+read call in one test method (348 tests
  reported 347 passed, 1 failed with an unexpected 404 instead of 200 —
  same "stale cached user" shape as above, just surfacing as a 404 this
  time since the wrong/absent profile was resolved rather than an
  authorization check misfiring). Fixed the same way: rewrote the test to
  create the `User`+`StudentProfile` directly via Eloquent, so it
  authenticates via HTTP as exactly one user. Two independent recurrences
  now confirm this is a structural constraint of this test suite, not a
  one-off — see the Decisions and Assumptions row above.
- The first real run of `.github/workflows/ci.yml` (GitHub Actions run
  `30308242547`, triggered by pushing `main`) came back 3/4 green:
  `backend`, `frontend`, and `docs` all passed; `ml-service` failed at the
  `pytest` step, with everything before it (install, ruff, mypy) passing.
  The identical suite (`pytest`, 6 tests) passed cleanly locally (Python
  3.14.3 on Windows), ruling out a test/code defect. Couldn't fetch the raw
  job log via the GitHub API to confirm the exact error (the logs endpoint
  403s without authentication, and `gh` isn't installed locally); asked the
  user to paste the failed step's output, but proceeded on the strongest
  available evidence rather than wait indefinitely. **Root cause:** the
  `ml-service` job installed from `requirements-dev.txt`, which pins only
  the 5 direct runtime packages and 4 dev tools — every transitive
  dependency (numpy, scipy, pydantic, starlette, etc.) resolves to whatever
  is newest on PyPI at the moment CI runs, not what was verified.
  `requirements.lock` (dated 2026-07-26) is a complete, fully-pinned freeze
  of the actually-verified environment — confirmed by creating an isolated
  venv and installing *only* from `requirements.lock`, which passed 6/6
  cleanly. Fixed by switching the CI job to install from `requirements.lock`
  instead of `requirements-dev.txt` (branch `fix/ci-ml-service-lockfile`).
  **This did not fix it** — the second run (`30309273294`) failed at the
  identical `pytest` step, with `Install dependencies` now succeeding
  against the exact locked versions, ruling out dependency drift entirely.
  A GitHub Actions job page's static HTML (fetched via `WebFetch`, since the
  raw-logs API 403s without authentication) surfaced one real signal —
  `Process completed with exit code 2`, pytest's collection/interrupted-run
  code rather than "some tests failed" — but not the actual traceback text
  (the log viewer needs JavaScript/auth to render). Checked whether a
  gitignored model artifact might exist locally but not in a fresh CI
  checkout: ruled out, `ml-service/models/` contains only the tracked
  `.gitkeep` in both places. Read `app/main.py`, `app/schemas/health.py`,
  and `tests/test_health.py` in full: no file I/O, no required environment
  variables, no OS-specific code paths, and no import of `pandas`/
  `scikit-learn`/`xgboost` anywhere in the code path this test suite
  actually exercises — nothing here should differ between Windows (where it
  passes locally, 6/6) and the `ubuntu-latest` CI runner. Asked the user for
  the raw log text a second time; they chose to pause this specific
  diagnosis rather than keep spending CI cycles on further guesses, since
  the other three jobs (`backend`, `frontend`, `docs`) are confirmed green
  and CI is otherwise live. Left as a known, explicitly paused gap rather
  than resolved.


---

## Files Changed in the Current Session

- Root: `.gitignore`, `AGENTS.md`, `PRD.md`, restored `PRD(1).md`,
  `PROGRESS.md`, `README.md`, and local `.git/` metadata.
- Backend: Laravel API-only scaffold, configuration, request-ID middleware,
  health controller/resource, safe error support, tests, Composer lockfile,
  Larastan configuration, safe environment example, and README.
- Frontend: React/Vite/Tailwind scaffold, registrar-ledger page, shadcn source
  components, API/query/schema layers, tests, local fonts, Prettier/ESLint/
  Oxlint/TypeScript configuration, lockfile, safe environment example, and
  README.
- Prediction service: FastAPI shell, schemas, safe errors/middleware, tests,
  exact-version manifests, safe environment example, model placeholder, and
  README.
- Documentation: six ADRs, version-compatibility matrix, public OpenAPI
  document, and shared error contract.
- Current demo-portal session: approved specification and ten-task TDD plan
  under `docs/superpowers/`; nine-account credential guide under
  `docs/testing/`; React Router/auth/session/catalog layers; institutional
  landing and login; responsive all-role portal shell, overview, and module
  preview/denial states; focused and full regression coverage; continuously
  updated `PROGRESS.md`.
- Supplied artifact preserved unchanged: `Casuncad, Westlie.pdf`.
- Authorization foundation and reference-data session (branch
  `feat/authz-foundation-reference-data`): `Organization` status enums,
  `UserRole::isLearnerScoped()`, `Program`/`AcademicTerm` models and query
  scopes, `EnsureUserHasRole` middleware, `ProgramPolicy`/`AcademicTermPolicy`,
  two new endpoints and Resources, two new seeders, a `bootstrap/app.php`
  fix for a pre-existing 500-instead-of-401 defect, ADR 0008, OpenAPI/data-
  dictionary/testing doc updates, and 40 new backend tests.
- Curriculum catalog session (branch `feat/curriculum-catalog`): `Curriculum`
  domain enums, `PrerequisiteCycleDetector`, `Subject`/`Curriculum`/
  `CurriculumSubject`/`SubjectPrerequisite` models (first FKs in this
  codebase), `SubjectPolicy`/`CurriculumPolicy`, four new endpoints and
  Resources, `StoreCurriculumRequest`/`UpdateCurriculumRequest`,
  `SynchronizeCurriculumSubjects` action, ADR 0009, OpenAPI/data-dictionary
  updates, and 52 new backend tests.
- Schema-foundation session (branch
  `feat/schedule-and-enrollment-schema-foundation`): adopted a 43-file
  untracked batch (13 migrations, 13 models, 10 domain enums, `SubjectSeeder`/
  `CurriculumSeeder`/`SectionSeeder`/`DemoEnrollmentSeeder`, 2 tests) found
  with zero git history — see Failure and Recovery Record. Added 24 unit
  tests (11 enums, 13 models) and 4 migration-constraint test files
  (`FacultyInputMigrationTest`, `SectionSchedulingMigrationTest`,
  `ScheduleProposalMigrationTest`, `TransfereeAndWithdrawalMigrationTest`)
  covering the 6 tables the found tests didn't reach. One real gap fixed:
  `StudentProfile.year_level` now casts to `integer`, matching every other
  tiny/small-int column in this codebase. 39 table-level `GRANT` statements
  (`grc_migrator`/`grc_test` CREATE+DDL+DML, then `grc_app` DML) across all 13
  tables, zero incidents. New `docs/data-dictionary/enrollment-records.md`;
  updated `curriculum-catalog.md`'s stale "no seeder" note.
- Faculty input session (branch `feat/faculty-input`): own-record
  `scopeVisibleTo()` on `FacultyAvailability`/`FacultySubjectPreference`,
  `FacultyAvailabilityPolicy`/`FacultySubjectPreferencePolicy`, `Store`/
  `UpdateFacultyAvailabilityRequest` and `Store`/
  `UpdateFacultySubjectPreferenceRequest` (composite uniqueness via
  `Rule::unique()->where()->ignore()`), `FacultyAvailabilityResource`/
  `FacultySubjectPreferenceResource`, `FacultyAvailabilityController`/
  `FacultySubjectPreferenceController` (index/store/update/destroy — the
  first `DELETE` endpoints in this API), 8 new routes gated `role:faculty`
  for writes, `docs/data-dictionary/faculty-input.md`, OpenAPI updates
  (1 new tag, 8 new paths, 10 new schemas), and 30 new backend tests.
- Section planning session (branch `feat/section-planning`):
  `App\Domain\Scheduling\ScheduleDayParser` (parses `schedule_days`
  shorthand into ISO-8601 day integers) and `SectionConflictDetector` (pure,
  persistence-free professor double-booking check — ADR 0010),
  `SectionStatus::isVisibleToLearners()`, `Section::scopeVisibleTo()`,
  `SectionPolicy`, `Store`/`UpdateSectionRequest`, `SectionResource`,
  `SectionController` (index/store/update), 3 new routes gated
  `role:program_chair` for writes, `docs/data-dictionary/section-planning.md`,
  OpenAPI updates (1 new tag, 2 new paths, 4 new schemas), and 34 new backend
  tests.
- Approval workflow session (branch `feat/schedule-approval-workflow`):
  `App\Actions\Scheduling\TransitionScheduleProposal` (applies one of six
  transitions, records who/when, bulk-publishes the term's planned sections
  on `publish` — ADR 0011), `ScheduleProposal::scopeVisibleTo()`,
  `ScheduleProposalPolicy` (four abilities instead of the usual pair —
  `approveAsDean`, `approveAsExecutive`, `publish`, `close`), `Store`/
  `UpdateScheduleProposalRequest`, `ScheduleProposalResource`,
  `ScheduleProposalController` — the first controller whose write route
  carries no `role:` middleware at all. 3 new routes. Docs: ADR 0011,
  `docs/data-dictionary/approval-workflow.md`, OpenAPI updates (1 new tag,
  3 new paths, 4 new schemas), and 23 new backend tests. This completes
  every unblocked PRD §5.1 sub-project — only demand forecast (blocked on
  Process 4.0) and cross-cutting audit logging remain.
- CI session (branch `feat/ci-quality-gates`): `.github/workflows/ci.yml` —
  four jobs (`backend`, `frontend`, `ml-service`, `docs`) running every
  quality gate already used manually all project (composer test/analyse/
  format:check/audit; npm format:check/lint/lint:fast/typecheck/test/build/
  audit; ruff/mypy/pytest/pip check/pip-audit; Redocly OpenAPI lint) on
  every push to `main` and every pull request. ADR 0012.
- Student profile foundation session (branch
  `feat/student-profile-foundation`): `App\Actions\Identity\ProvisionStudent`
  (creates a `User`+`StudentProfile` together in one transaction),
  `StudentProfilePolicy` (own-record only, no broader role visibility — a
  new authorization shape), `StoreStudentProfileRequest` (curriculum/program
  mismatch check), `StudentProfileResource`, `StudentProfileController`
  (`store`/`show`), 2 new routes (`role:admission_staff` for provisioning;
  no role gate beyond authentication for the self-read). First production
  consumer of the `admission_staff` role. `docs/data-dictionary/student-profile-foundation.md`,
  OpenAPI updates (1 new tag, 2 new paths, 3 new schemas), and 13 new backend
  tests (4 Policy, 9 endpoint — including `ApiSurfaceTest` additions). First
  sub-project of PRD §5.2 (Process 2.0); eligible-subject-pool and
  enrollment-submission named as the next two.


---

## Session Handoff Log

### 2026-07-26 16:19 +08:00

**Goal:** Establish mandatory continuity records and begin the Phase 0 audit.  
**Completed:** Repository/Git inspection, complete PRD review, duplicate hash verification, initial runtime audit, and continuity-file creation.  
**Not completed:** MySQL binary/service check, stable-version compatibility research, service scaffolding, and quality checks.  
**Tests run:** No application tests exist yet. Read-only repository and runtime checks only.  
**Known issues:** Git is not initialized; `mysql` is not on `PATH`; all PRD §17 institutional decisions remain open.  
**Next exact step:** Check `C:\xampp\mysql\bin\mysql.exe --version`, then document compatible stack versions before scaffolding the first service.

### 2026-07-26 17:33 +08:00

**Goal:** Audit the empty repository and implement the earliest safe PRD slice.  
**Completed:** Canonical repository records, version/architecture decisions,
three runnable Phase 0A shells, public/private health and error contracts,
security review corrections, automated quality gates, dependency audits, and
integrated HTTP smoke verification.  
**Not completed:** MySQL-backed schema, deterministic role seeders, CI,
authentication/RBAC, business workflows, or interactive browser visual QA.  
**Tests run:** Backend 11 tests/61 assertions; frontend 3 files/9 tests; ML 6
tests; all applicable format, lint, static-analysis, build, integrity,
dependency-audit, OpenAPI, and live HTTP checks listed above passed.  
**Known issues:** MySQL 8.4 LTS and a current PHP runtime are unavailable;
institutional PRD §17 values remain open; no browser session was connected.  
**Next exact step:** Provision a least-privileged MySQL 8.4 LTS development
database, then implement and verify reversible PRD §10.1 base migrations and the
nine-role deterministic seeder.

### 2026-07-26 18:41 +08:00

**Goal:** Design the requested landing page, login, all-role demo credentials,
and role-aware portal without misrepresenting frontend fixtures as real
authentication.
**Completed:** Current code/PRD review, approach comparison, user-approved
architecture, screen content, all-nine-role credential matrix, portal
navigation, demo-session boundaries, failure behavior, accessibility, and test
acceptance criteria. The written specification is
`docs/superpowers/specs/2026-07-26-landing-login-demo-portal-design.md`.
**Not completed:** Implementation plan, frontend implementation, real Laravel
authentication, MySQL users/seeders, or browser visual verification.
**Tests run:** No application code changed in this design stage, so application
tests were not rerun. The design self-check found zero placeholder flags,
exactly nine unique credential emails, and the required shared password.
**Known issues:** MySQL 8.4 and real Sanctum authentication remain blocked; the
design document is intentionally uncommitted because `AGENTS.md` prohibits
committing without explicit user authorization.
**Next exact step:** User reviews the written design, then a TDD implementation
plan is created.

### 2026-07-26 18:57 +08:00

**Goal:** Convert the approved landing/login/demo-portal design into an
executable red-green-refactor plan.
**Completed:** Verified planned dependency compatibility and current shadcn
configuration; advanced the approved design status; created and self-reviewed
the ten-task implementation plan at
`docs/superpowers/plans/2026-07-26-landing-login-demo-portal.md`. The plan
includes exact source/test/documentation files, all-nine-role credentials,
safe-route and storage boundaries, UI requirements, static scans, browser QA,
and the full frontend gate.
**Not completed:** Frontend implementation, dependency installation, demo
credential fixture/document creation, real Laravel authentication, MySQL
users/seeders, or browser visual verification.
**Tests run:** No application code or dependency changed in planning, so
application tests were not rerun. Plan inspection confirmed 10 task headings
and 1,050 lines; placeholder-scan matches were only the plan's explicit
prohibition and its future scan command.
**Known issues:** MySQL 8.4 and real Sanctum authentication remain blocked; no
browser session is currently known to be available; implementation remains
uncommitted because `AGENTS.md` prohibits commits without explicit user
authorization.
**Next exact step:** Select the execution workflow, then begin Task 1 with a
failing auth-mode test and exact dependency installation.

### 2026-07-26 22:42 +08:00

**Goal:** Execute the approved landing/login/demo-portal plan and provide
testable credentials for every user role.
**Completed:** All ten tasks through TDD; production-safe demo-mode selection;
nine synchronized synthetic accounts and `docs/testing/DEMO_CREDENTIALS.md`;
strict session persistence; safe return paths and route guards; institutional
landing, login, responsive all-role portal, role catalog, overview cards,
module previews, scoped denial states, operator documentation, and reviewed
static safety boundaries.
**Not completed:** Real Laravel/Sanctum authentication, MySQL users/role
seeders, backend authorization policies, business workflow APIs, CI, or
interactive visual/browser QA. No commit, push, merge, or pull request was
created.
**Tests run:** Final frontend gate passed Prettier, ESLint, Oxlint, strict
TypeScript, 16 Vitest files/140 tests, Vite production build, and audit with 0
vulnerabilities. A fresh completion rerun again passed 16 files/140 tests.
**Known issues:** Browser discovery returned no available browser, so mobile,
wide-screen, keyboard, zoom, and reduced-motion visual QA remain unverified.
MySQL 8.4, real Sanctum authentication/authorization, institutional policy
values, and CI remain deferred.
**Next exact step:** Choose the feature-branch integration option, then
provision the supported MySQL 8.4 identity baseline before implementing the
real Sanctum vertical slice.

### 2026-07-26 23:01 +08:00

**Goal:** Merge the completed demo-portal feature back to local `main`.
**Completed:** Created local feature commit `292e3c4`, preserved the earlier
root records in a named stash, fast-forwarded `main`, completed the full merged
frontend gate, removed the verified feature worktree, and deleted the merged
feature branch.
**Not completed:** No push or pull request was created. Real MySQL/Sanctum
authentication, server authorization, business workflow APIs, CI, and
interactive browser visual QA remain deferred.
**Tests run:** On merged `main`, `npm ci --ignore-scripts` completed with 0
vulnerabilities; Prettier, ESLint, Oxlint, strict TypeScript, 16 Vitest
files/140 tests, Vite production build, and the moderate-level dependency audit
all passed.
**Known issues:** The first clean install was blocked by two verified workspace
Vite processes holding the native Rolldown binary; both processes were stopped,
ports 5173/5174 confirmed closed, and the retry passed. Browser discovery still
returns no available browser.
**Next exact step:** Re-run browser QA when available, then provision the
supported MySQL 8.4 identity baseline before the real Sanctum vertical slice.

### 2026-07-26 23:15 +08:00

**Goal:** Restore usable local demo login access and verify the UI-only portals
before continuing the PRD.
**Completed:** Traced the disabled form to a missing Vite demo-mode input on a
fresh checkout; added a failing regression, made development default to demo
with an explicit local disable override, preserved production disablement,
updated setup documentation, confirmed the active Vite server serves the fix,
and reran the complete frontend gate.
**Not completed:** Live Playwright interaction/visual QA remains unavailable
because the MCP browser runtime has no connected browser. Real Sanctum auth,
MySQL users/roles, API authorization, CI, and business workflows remain
deferred.
**Tests run:** Focused login/router/portal coverage passed 5 files/79 tests.
The complete gate passed Prettier, ESLint, Oxlint, strict TypeScript, 16 Vitest
files/144 tests, Vite production build, and audit with 0 vulnerabilities.
**Known issues:** The workstation still provides MariaDB 10.4.32 and PHP
8.2.12, not the PRD's supported MySQL 8.4/current PHP production baseline.
**Next exact step:** Refresh `http://127.0.0.1:5173/login` and test the
documented synthetic roles; repeat MCP Playwright QA when a browser connects,
then provision MySQL 8.4 before implementing real Sanctum authentication.

### 2026-07-26 23:24 +08:00

**Goal:** Define the next PRD-aligned database foundation without disrupting
the existing XAMPP MariaDB environment.
**Completed:** Audited the local database/backend baseline; compared portable
MySQL, Windows service, and MariaDB approaches; received approval for an
isolated official MySQL 8.4 LTS instance on `127.0.0.1:3307`; and created the
review-ready design at
`docs/superpowers/specs/2026-07-26-mysql84-identity-foundation-design.md`. The
design specifies checksum verification, loopback-only operation,
least-privileged development/test principals, three reversible identity
foundation tables, a nine-value role enum, and safe deterministic seed data
whose password must come from an ignored environment variable.
**Not completed:** The MySQL archive has not been downloaded or installed;
databases, principals, migrations, enum, seeders, tests, and lifecycle scripts
have not been implemented. Sanctum and real portal authentication remain a
later slice.
**Tests run:** No runtime or application code changed during design, so tests
were not rerun. Written-design checks found no placeholder markers, exactly
nine unique synthetic role emails, and no diff whitespace errors. Self-review
removed premature `curricula` and `student_profiles` tables and their
unconfirmed policy values; the slice now uses only the unambiguous `users`,
`programs`, and `academic_terms` foundation.
**Known issues:** The active local database remains MariaDB 10.4.32 on port
`3306`. Live Playwright visual QA remains unavailable because no browser is
connected to the MCP runtime.
**Next exact step:** Self-review the written database design, obtain user
approval, then create the TDD implementation plan before provisioning MySQL.

### 2026-07-26 23:24 +08:00 — Planning started

**Goal:** Convert the approved MySQL 8.4 identity-foundation design into an
exact, test-driven implementation plan.
**Completed:** User approved the written design; reread `AGENTS.md`, `PRD.md`,
and the complete progress record; mapped the backend, test, configuration, and
documentation surfaces; confirmed that `scripts/`, models, migrations, and
seeders do not yet exist; and aligned the planned PHP role values with the
existing frontend credential contract.
**Not completed:** The implementation plan is still being written. No MySQL
download, instance configuration, database mutation, migration, seeder, or
application implementation has started.
**Tests run:** No runtime/application code changed, so application tests were
not rerun.
**Known issues:** The existing XAMPP database remains MariaDB 10.4.32 on port
`3306`; MySQL 8.4 must remain isolated on `3307`. The working tree also retains
the previously verified login correction and documentation changes.
**Next exact step:** Finish and self-review the plan against every approved
design requirement, then offer the supported execution workflows.

### 2026-07-26 23:47 +08:00

**Goal:** Finish a directly executable TDD plan for the approved MySQL 8.4
identity foundation.
**Completed:** Created
`docs/superpowers/plans/2026-07-26-mysql84-identity-foundation.md` with nine
tasks and 65 checkbox checkpoints covering canonical identity types,
dependency-free PowerShell safety tests, bootstrap/lifecycle scripts, Laravel
connections, isolated provisioning, reversible migrations, the guarded
nine-role seeder, engine/grant verification, data dictionary, runbook, and
final quality gates. Self-review aligned four role IDs with the existing portal
contract, removed unresolved implementation labels, added Windows PowerShell
5.1-compatible secret generation, added DPAPI-protected administrator
credential persistence, and supplied direct model/constraint/privilege tests.
The approved design was synchronized with the canonical roles and encrypted
administrator-credential boundary.
**Not completed:** No implementation task, worktree, download, installation,
database/principal creation, migration, seeding, or runtime mutation has
started. No commit or push was created.
**Tests run:** Planning checks confirmed nine tasks, 65 executable steps, 1,752
plan lines, exactly nine unique seed emails, coverage of every required
runtime/security/rollback/documentation term, zero unresolved-marker matches,
and zero `git diff --check` errors. Application tests were not rerun because
only planning and progress documents changed.
**Known issues:** MySQL 8.4 is still absent and MariaDB 10.4.32 remains the
local database. The current `main` checkout is intentionally dirty with the
previously verified login correction plus this uncommitted design/plan, so
execution must begin through the repository's worktree workflow.
**Next exact step:** Choose subagent-driven or inline execution, then use the
required execution/worktree/TDD skills to begin Task 1 without altering the
dirty root checkout.

### 2026-07-26 23:59 +08:00 — Execution started

**Goal:** Execute the approved nine-task MySQL identity-foundation plan through
fresh task implementers and independent task reviews.
**Completed:** User selected and explicitly authorized subagent-driven
execution. Loaded the subagent, worktree, TDD, and test-quality workflows;
confirmed the root checkout is normal `main`, `.worktrees/` exists and is
ignored through `.git/info/exclude`, and no conflicting feature branch or
linked worktree exists.
**Not completed:** The isolated worktree, SDD ledger, baseline dependency
installation/tests, Task 1 implementation, reviews, and every MySQL mutation
remain pending.
**Tests run:** Read-only Git/worktree preflight only; no application test has
run in the new execution workspace yet.
**Known issues:** Repository instructions prohibit commits without a separate
explicit request, so this SDD run will use task-scoped uncommitted review
packages. Preflight is also checking whether the planned PowerShell
source-contract harness should remain a lint gate while Task 5 supplies the
required real lifecycle behavior verification.
**Next exact step:** Create and baseline the isolated feature worktree, create
the plan-specific SDD ledger, then resolve any load-bearing plan/review-rule
conflict before dispatching Task 1.

### 2026-07-27 00:05 +08:00 — MySQL identity foundation Task 1 started

**Goal:** Add only the database-independent canonical technical identity types
and narrow Eloquent user model for the Phase 0 identity foundation.
**Scope:** No schema exists yet; this task adds no migrations, database access,
Sanctum behavior, authentication flow, or institutional policy values.
**Next exact step:** Create the specified failing enum/model unit tests, then
implement only the canonical role/status types and model casts required by the
approved Task 1 brief.

**Checkpoint:** The specified focused red run failed as expected because
`UserRole`, `UserStatus`, and `User` did not exist. After the minimal
implementation, the same run passed 4 tests/7 assertions. The first
`composer format:check` then failed only on Pint's `new_with_parentheses` rule
in `tests/Unit/Models/UserTest.php`; no production behavior failed or changed.

**Completed:** Added the nine-value `UserRole` enum with stable labels, the
two-value technical `UserStatus` enum, and the narrow `User` model with only
the approved fillable, hidden, password/hash, enum, and immutable-login casts.
No schema, database operation, Sanctum trait, authentication flow, or policy
value was added.
**Tests run:** After correcting the test-only Pint style finding,
`php artisan test tests/Unit/Domain/Identity/UserRoleTest.php tests/Unit/Domain/Identity/UserStatusTest.php tests/Unit/Models/UserTest.php`
passed 4 tests/7 assertions; `composer format:check` passed; `composer analyse`
passed with no errors across 17 files; and full `composer test` passed 15
tests/68 assertions.
**Self-review:** `git diff --check` passed with no output. The implementation
matches the approved role IDs, labels, status values, and casts verbatim; the
model deliberately excludes `HasApiTokens` because Sanctum belongs to a later
slice. No commit or push was created.
**Next exact step:** Perform the task-scoped independent review, then continue
with the next approved identity-foundation task.


### 2026-07-27 02:07–02:20 +08:00 — MySQL 8.4 lifecycle scripts (Tasks 2–3, collapsed)

**Summary:** The PowerShell safety library and MySQL 8.4 bootstrap/lifecycle
scripts (old Task 2 and Task 3: `scripts/mysql84/MySql84.Common.ps1`,
`bootstrap.ps1`, `start.ps1`, `status.ps1`, `stop.ps1`, and their test/scan
harnesses) were implemented and passed four rounds of independent
security/spec review (canonical path handling, process argument/quoting
integrity, initialization-password/TOCTOU/cleanup safety, exact `my.ini`
option-file validation, and MySQL comment/alias-character path rejection plus
byte-exact UTF-8/LF generation). Every round found and fixed a real defect,
and every fix, RED/GREEN cycle, and non-runtime verification pass ran clean.
No MySQL 8.4 download, extraction, initialization, or server process was ever
executed at any point: `C:\xampp\mysql84` remained absent and port `3307`
never had a listener through every round. The scripts were reviewing
themselves correctly; nothing had verified them against a real MySQL server.
**Superseded 2026-07-27:** the user chose to use the existing XAMPP MariaDB
10.4.32 instance instead of provisioning an isolated MySQL 8.4 install.
`scripts/mysql84/` (8 files, 2,628 lines) was deleted; the approved design and
plan were marked superseded in place; `docs/adr/0007-mariadb-development-database.md`
records the rationale and PRD deviation; `docs/architecture/version-compatibility.md`
was updated. The full round-by-round detail remains in
`.superpowers/sdd/2026-07-26-mysql84-identity-foundation/task-3-report.md` and
the SDD ledger. The replacement plan is
`docs/superpowers/plans/2026-07-27-mariadb-identity-sanctum-auth.md`, which
also folds in the Sanctum authentication vertical slice.
**Carried forward unchanged:** old Task 1's canonical identity types and
`User` model (`backend/app/Domain/Identity/UserRole.php`,
`UserStatus.php`, `backend/app/Models/User.php`) are database-agnostic and
remain the foundation for the MariaDB-based migrations.
**Next exact step:** Begin the replacement plan's Task 2 — create
`grc_enrollment`/`grc_enrollment_test` and the three scoped principals against
the live MariaDB instance.

### 2026-07-27 18:00–18:20 +08:00 — Task 2: databases and principals, with a real incident

**Goal:** Create `grc_enrollment`/`grc_enrollment_test` and the three scoped
principals against the live MariaDB instance.
**Found first:** `SHOW DATABASES` revealed `grc_enrollment` already existed,
with 18 tables and real-looking data (12 `system_users`, 4
`faculty_schedules`, etc.), all created 2026-07-23 — before this repo's git
history starts. Not created by this project. User confirmed it was disposable
prototype data. Backed up via `mysqldump` to
`C:\xampp\mysql-backups\grc_enrollment_prototype_backup_20260723.sql` before
dropping and recreating the database fresh.
**Incident:** The first schema-wildcard `GRANT SELECT, INSERT, UPDATE, DELETE
ON grc_enrollment.* TO 'grc_app'@'127.0.0.1'` failed with `Got error 176 "Read
page with wrong checksum" from storage engine Aria` — the `mysql.db` system
table (MariaDB's schema-grant table) was corrupted. `CHECK TABLE` confirmed
`mysql.global_priv`/`tables_priv`/`columns_priv`/`procs_priv` were all healthy;
only `mysql.db` was affected. With explicit user approval, ran `REPAIR TABLE
mysql.db`, which fixed the corruption but reset the table from 3 rows to 0,
losing `pma@localhost`'s (phpMyAdmin's control user) grant on its own
`phpmyadmin` database. The next write to that table (`FLUSH PRIVILEGES` after
a `GRANT` attempting to restore `pma`'s access) crashed `mysqld.exe` entirely
(Windows Event Log: exception `0xc0000005` in `VCRUNTIME140.dll`). User
restarted MariaDB via XAMPP Control Panel; a fresh plain schema-wildcard
`GRANT` on `grc_enrollment.*` crashed it again with the **identical** fault
signature (same exception, same module, same byte offset), even though
`CHECK TABLE mysql.db` reported clean immediately beforehand. Two identical
crashes on a table that checks out clean rules out ordinary data corruption as
the proximate cause — this points to a broken/corrupted
`C:\xampp\mysql\bin\VCRUNTIME140.dll`, not the database itself. Not caused by
this session; a separate, unrelated `mysqld.exe` crash on 2026-07-24 (Windows
Event Log) predates any agent involvement and is the likely origin of the
`mysql.db` corruption in the first place.
**Workaround, user-approved:** table-level `GRANT ... ON db.specific_table TO
...` statements (routed through `mysql.tables_priv`, confirmed healthy through
both crashes) do not trigger the crash. Used this exclusively from this point:
`grc_migrator` and `grc_test` each received `CREATE, ALTER, DROP, INDEX,
SELECT, INSERT, UPDATE, DELETE` on all five planned table names (`migrations`,
`users`, `personal_access_tokens`, `programs`, `academic_terms`) in their
respective databases — valid pre-creation because `CREATE` is the one
privilege MySQL/MariaDB allows granting on a not-yet-existing table.
`grc_app`'s `SELECT, INSERT, UPDATE, DELETE`-only grant attempt on the same
tables correctly failed with `ERROR 1146 table doesn't exist` (expected,
documented behavior, not a crash) and is deliberately deferred to the start of
Task 4, once the tables are real. `pma`'s access was fully restored via the
same table-level approach across its 19 real `phpmyadmin.*` tables — server
survived all 20+ table-level GRANT statements without incident.
**State after this task:** MariaDB running (verified via `netstat` + `SELECT
1`). `grc_enrollment`/`grc_enrollment_test` exist with the intended
`utf8mb4`/`utf8mb4_unicode_ci` collation. `grc_app`/`grc_migrator`/`grc_test`
exist; `grc_migrator`/`grc_test` hold their full planned grants, `grc_app`
holds none yet (by design). `pma` fully restored. No other pre-existing
database (`b1g_timer_dev`, `harmonyhub`, `login_system`, `sixt_sense_spa`,
`sixth_sense_spa`, `smartload`, `websystem`, `test`) was touched. The three
generated passwords exist only in the now-exited PowerShell process memory —
never written to any file — and must be reset via `ALTER USER ... IDENTIFIED
BY` when Task 3 writes `backend/.env`/`.env.testing`.
**Known issue carried forward:** the underlying `VCRUNTIME140.dll` problem is
unresolved. Schema-wildcard `GRANT ... ON db.*` must not be attempted again
against this instance without the user first replacing that DLL (fresh XAMPP
extraction or a matching VC++ Redistributable install) and confirming a
schema-wildcard grant survives.
**Next exact step:** Task 3 — Laravel `mariadb` connection config, `.env`/
`.env.testing`, `phpunit.xml`, and `DatabaseConfigurationTest`.

### 2026-07-27 18:25 +08:00 — Task 3: Laravel connection and environment config

**Completed:** Reset the three principals' passwords (never persisted before)
via `ALTER USER` and wrote them directly into `backend/.env` (`DB_CONNECTION=
mariadb`, `grc_app`/`grc_enrollment`) and a new `backend/.env.testing`
(`grc_test`/`grc_enrollment_test`) without ever displaying or logging a
password. Added a `mariadb_migrator` connection to `config/database.php`
(reads `DB_MIGRATOR_USERNAME`/`DB_MIGRATOR_PASSWORD`) so local migrations run
as `grc_migrator` via `php artisan migrate --database=mariadb_migrator`,
keeping the app's default connection scoped to `grc_app`'s DML-only grants —
a one-flag deviation from the original design's "no manual step" wording,
accepted as a reasonable least-privilege trade-off. Updated `.env.example`
(`mysql`→`mariadb`, added migrator template vars), added
`.env.testing.example`, added `.env.testing` to `.gitignore`, and removed
phpunit.xml's stale commented sqlite lines.
**Discovered and worked through:** the default `mariadb` connection (`grc_app`)
cannot even select `grc_enrollment` as its default database yet — MariaDB
requires at least one privilege in a database before `USE` succeeds, and
`grc_app`'s grants are deliberately deferred to Task 4. Verified instead
through the new `mariadb_migrator` connection, which already holds real
table-level grants; `DatabaseConfigurationTest` checks connection config
directly via `DB::connection()` (works because PHPUnit's `APP_ENV=testing`
loads `.env.testing`, where `grc_test` already has full rights on
`grc_enrollment_test`) rather than asserting against the not-yet-usable
dev `grc_app` connection.
**Tests run:** `tests/Feature/Database/DatabaseConfigurationTest.php` — 5
tests/11 assertions, all against the real live MariaDB server (version string,
charset, collation, strict SQL mode, migrator connection config). Full
`composer test`: 20 tests/79 assertions passed. `composer format:check` and
`composer analyse` (Larastan level 8, 17 files) both passed.
**Next exact step:** Task 4 — reversible migrations for `users`, `programs`,
`academic_terms`, and Sanctum's `personal_access_tokens`, run via
`grc_migrator`; then grant `grc_app`'s deferred DML privileges once the real
tables exist.

### 2026-07-27 18:35 +08:00 — Tasks 4 and 6: migrations, Sanctum install, and a caught PRD violation

**Completed (Task 4):** Installed `laravel/sanctum` v4.3.3 (pulled forward from
Task 6 because Task 4 needs its migration), published and renamed its migration
to `2026_07_26_000004_create_personal_access_tokens_table.php` to fit the
slice's ordering, and added `HasApiTokens` to `App\Models\User`. Wrote
`tests/Feature/Database/IdentityFoundationMigrationTest.php` first and
confirmed RED (7 failed / 1 passed), then implemented the three reversible
migrations for `users`, `programs`, and `academic_terms` exactly as the
approved design specifies — `role`/`status` as application-backed strings
rather than MySQL `ENUM`, no foreign keys in this slice, unique `email`,
unique `code`, and unique `(school_year, semester)`.
**Migrations applied:** `php artisan migrate --database=mariadb_migrator`
created all four tables in `grc_enrollment`. Verified every table is `InnoDB`
with `utf8mb4_unicode_ci`.
**Deferred grants completed:** With the tables now real, `grc_app` received its
`SELECT, INSERT, UPDATE, DELETE` grants on `users`,
`personal_access_tokens`, `programs`, and `academic_terms` (deliberately not
`migrations`), using table-level statements per the Task 2 workaround. Server
survived every batch. Least-privilege verified live: `grc_app` can read
`users` but a `CREATE TABLE` attempt through its connection is correctly
denied.
**PRD violation caught by a test (Task 6):** the pre-existing `ApiSurfaceTest`
route-inventory assertion failed the moment Sanctum was installed, because
Sanctum auto-registers `GET|HEAD sanctum/csrf-cookie` — precisely what PRD §9.1
forbids ("Do not use session cookies, CSRF-cookie endpoints, or
`withCredentials`"). Published `config/sanctum.php` and set `routes => false`,
`stateful => []`, `guard => []` (stock `['web']` would consult the session
guard before falling back to bearer tokens), and `middleware => []`. Route
inventory is back to exactly `GET|HEAD api/v1/health`. Added
`tests/Feature/Auth/SanctumConfigurationTest.php` (7 tests) so this boundary
cannot silently drift back.
**Token expiration flagged, not invented:** PRD §9.1 requires an approved
expiration policy and PRD §17 lists it as an open institutional decision.
`config/sanctum.php` reads `SANCTUM_TOKEN_EXPIRATION` with a **provisional**
480-minute local default, explicitly documented in the config and
`.env.example` as not an approved policy value. Sanctum's own default (`null`
= tokens never expire) was deliberately rejected so an unset policy fails safe.
**Tests run:** full `composer test` — 35 tests/109 assertions passed, including
8 migration tests (column presence, all three unique constraints enforced
against the live server, and a full `migrate:rollback` → `migrate` reversibility
cycle). `composer format:check` passed after importing `QueryException` rather
than using inline FQCNs. `composer analyse` (Larastan level 8) passed with no
errors. `composer audit --locked` found no advisories.
**Next exact step:** Task 5 — the deterministic nine-role seeder, guarded by
`GRC_SEED_PASSWORD` and restricted to local/testing environments.

### 2026-07-27 18:45 +08:00 — Task 5: deterministic nine-role seeder

**Completed:** Wrote `RoleUserSeederTest.php` first (RED: 8 failed), then
implemented `database/seeders/RoleUserSeeder.php` and `DatabaseSeeder.php`.
The seeder upserts exactly the nine approved synthetic identities keyed on
email inside a transaction, reads `GRC_SEED_PASSWORD` from the environment,
hashes through Laravel's `hashed` cast, and refuses to run outside `local`/
`testing`. Seeded the real dev database and verified all nine rows: one per
role, all `active`, all passwords stored as 60-character bcrypt hashes, no
plain text.
**Test correction during TDD:** the environment-guard test initially failed
for the wrong reason — `db:seed` in a faked production environment hits
Laravel's own interactive confirmation prompt before the seeder body runs, so
the assertion saw a Mockery error rather than the guard. Rewrote it to invoke
`app(RoleUserSeeder::class)->run()` directly, which is also the more valuable
assertion: it proves the seeder is safe when chained programmatically from
another seeder, where the command-level prompt would not protect it. Added two
further tests asserting that a refused run seeds *nothing* (not a partial
write) in both the wrong-environment and missing-password cases.
**Secret handling:** the seed password was generated locally with
`RandomNumberGenerator`, written only into the gitignored `backend/.env`, and
never printed, logged, echoed, or recorded in this file or
`docs/testing/SEEDED_IDENTITIES.md`.
**Documentation:** added `docs/testing/SEEDED_IDENTITIES.md` listing the nine
role/name/email triples, the safety guarantees each test enforces, and an
explicit comparison table separating these database fixtures from the UI-only
`DEMO_CREDENTIALS.md` accounts — the two sets must never share a password.
**Quality-gate improvement (unplanned, recorded per AGENTS.md):** `phpstan.neon`
scanned only `app`, `bootstrap`, and `routes`, so the new seeder and all four
migrations were invisible to static analysis. Added `database` to the analysed
paths; coverage went from 17 to 23 files and still passes Larastan level 8
with no errors.
**Tests run:** `RoleUserSeederTest` — 10 tests/42 assertions. Full
`composer test` — 45 tests/151 assertions passed. `composer format:check`
passed. `composer analyse` passed across 23 files.
**Next exact step:** Task 7 — `POST /api/v1/auth/login`, `POST
/api/v1/auth/logout`, and `GET /api/v1/auth/me` with a Form Request, an
Action, `AuthResource`, generic credential failures, and login rate limiting.

### 2026-07-27 18:50 +08:00 — Task 7: Sanctum auth endpoints

**Completed:** Wrote `LoginEndpointTest` (14 tests) and `SessionEndpointTest`
(9 tests) first, then implemented the three PRD §8.4 routes:
`POST /api/v1/auth/login` (public, throttled), `POST /api/v1/auth/logout` and
`GET /api/v1/auth/me` (both `auth:sanctum`). Layering follows the repository's
existing conventions: `LoginRequest` Form Request (validates, lowercases and
trims the email before validation, and owns the throttle key),
`App\Actions\Auth\AuthenticateUser` action (verifies, rejects non-active
accounts, issues the token and stamps `last_login_at` in one transaction),
`AuthResource` as the single path a token may leave through per PRD §9.1, and
`UserResource` for `/me` with an exact key set that never includes the hash.
**Security properties implemented and tested:** unknown email, wrong password,
and disabled account all raise one `InvalidCredentialsException` rendered as an
identical generic 401, so the endpoint cannot enumerate accounts; a dummy
`Hash::check` runs on the missing-user path so response timing does not leak
existence either. Login throttles at 5 attempts per **email+IP** (verified live
that exhausting one account does not lock out another). Logout revokes only the
presented token, leaving other devices signed in. A new `EnsureUserIsActive`
middleware rejects and deletes a still-valid token whose account was disabled
after issue — without it, disabling a user would only block new logins.
`Cache-Control: no-store, private` on every token-bearing response.
**Contract correction during TDD:** the first test run failed because the tests
expected an unwrapped body. The existing API contract (`HealthResource`,
`docs/api/openapi.yaml`, and `HealthEndpointTest`'s `assertExactJson`) uses a
`data` wrapper for success and `error` for failures. The tests were wrong, not
the implementation; they now assert `data.*` paths, keeping the new endpoints
consistent with the documented envelope.
**Test-artifact diagnosis:** `a revoked token can no longer authenticate`
initially returned 200. The token row *was* deleted (the sibling test asserts
`personal_access_tokens` count drops), so this was Laravel's auth guard caching
the already-resolved user across calls within one test method rather than a
real defect. The test now calls `forgetGuards()` to reproduce a fresh request,
and the **live HTTP smoke test independently confirmed the real behavior is
401**.
**Static analysis fix (root cause, not suppression):** Larastan reported 8
errors because `App\Models\User` had no property annotations, so `role` and
`status` were inferred as `string` rather than their enum types — which also
made a status comparison look always-true and its following code unreachable.
Added `@property` docblocks for all columns plus `list<string>`/`array<string,
string>` annotations, and replaced one nullsafe call on a non-nullable Sanctum
return with an explicit `instanceof` check. No `@phpstan-ignore`, baseline
entry, cast, or widened type was used.
**Tests run:** auth suites 23 tests/87 assertions. Full `composer test` — 70
tests/250 assertions passed. `composer format:check` passed. `composer analyse`
passed with no errors across 32 files.
**Live HTTP smoke verification** (temporary server on 127.0.0.1:8100, since
stopped and the port confirmed closed): login returned 200 with the exact
envelope, `Cache-Control: no-store, private`, `token_type=Bearer`,
`expires_at=2026-07-27T18:46:36Z` (ISO-8601 UTC), correct `role_label`, and no
bcrypt hash anywhere in the body; `/me` with the bearer returned the identity;
logout returned 204; **reusing the revoked token returned 401**; wrong-password
and unknown-email returned byte-identical messages; the 6th bad attempt
returned 429 with `Retry-After: 58` while a different account still logged in
successfully.
**Next exact step:** Task 8 — frontend `auth-token` module, `postJson` plus
bearer/401 handling in `api-client`, Zod-validated `auth-service`, and an
`api` auth gateway replacing the demo fixtures as the default.

### 2026-07-27 19:05 +08:00 — Task 8: frontend real authentication

**Completed:** Frontend dependencies were never installed in this worktree
(`node_modules` absent); ran `npm ci --ignore-scripts` (343 packages, 0
vulnerabilities) before any typecheck/test could run.
Added `auth-token.ts` as the sole owner of the bearer token in `localStorage`,
deliberately separate from the demo session's `sessionStorage`. Extended
`api-client.ts` with `postJson`/`getAuthenticatedJson`/`postAuthenticatedJson`,
a registered token provider so the client never imports the token store
directly, and a 401 handler that clears the token — scoped to authenticated
calls only, so login's own 401 (bad credentials) does not trigger it. Added
`auth-schema.ts` (strict Zod, mirrors `AuthResource`/`UserResource` exactly)
and `auth-service.ts`. Added `api-auth-gateway.ts` implementing the existing
`DemoAuthGateway` interface, extended additively with optional
`persistsSessions`/`restore`/`signOut` so the 144 pre-existing demo/disabled
tests needed no changes to their gateway contract. `demo-auth-mode.ts` gained
an `"api"` mode as the default everywhere (`demo` and `disabled` now both
require explicit opt-in); `main.tsx` wires the token store, provider/handler
registration, and the three gateways by mode.
**Real defect caught by a new test, not by inspection:** the login page
computed `demoDisabled = authMode !== "demo"`, so under the new `api` default
the sign-in form would have been disabled and shown "Interface
demonstration—not real authentication" / "Laravel does not accept them" to
real users. Rewrote it with mode-specific copy (`api`/`demo`/`disabled`, each
with its own eyebrow, intro, credential-guide pointer, and generic- vs.
demo-specific invalid-credentials message) and changed the disable condition
to `authMode === "disabled"` only. Also fixed the route guard's hardcoded
"Restoring demo session…" text, now "Restoring your session…" since it covers
both modes.
**Real bug caught by a new test, not by inspection (second one):** the new
API-mode restore path called `gateway.restore?.().then(...)` — when a gateway
declares `persistsSessions: true` but implements no `restore` (a valid shape
per the now-optional interface), `gateway.restore?.()` evaluates to
`undefined` and `.then` on `undefined` threw, leaving every route stuck on
"Restoring your session…" forever. A new login-page test using exactly that
minimal gateway shape caught it; fixed with
`gateway.restore?.() ?? Promise.resolve(null)`.
**Contract correction:** `auth-schema.ts` and the new service/gateway tests
initially assumed an unwrapped response body; the existing contract wraps
success in `{"data": ...}` (matching `HealthResource` and
`docs/api/openapi.yaml`), so schemas and assertions were corrected to that
shape, not the backend.
**Boundary scans:** exactly one raw `fetch(` (`api-client.ts`; the other match
was `refetch()` from TanStack Query), exactly one `localStorage` user
(`auth-token.ts`), exactly one `sessionStorage` user
(`demo-session-store.ts`; `auth-token.ts`'s only match was a doc-comment
naming that file).
**Tests run:** added 4 test files — `auth-token.test.ts` (5),
`api-client.auth.test.ts` (6), `auth-service.test.ts` (5),
`api-auth-gateway.test.ts` (7) — plus 2 new/updated login-page API-mode tests
and a rewritten `demo-auth-mode.test.ts`. Full suite: 20 files, **170 tests**,
all passing. `format:check`, `lint` (ESLint, 0 warnings), `lint:fast` (Oxlint),
and `typecheck` all passed. `npm run build` succeeded (508.80 kB / 158.22 kB
gzip — a chunk-size warning only, not an error). `npm audit
--audit-level=moderate` found 0 vulnerabilities.
**Documentation:** rewrote `frontend/README.md` (removed the now-false
"Deferred Production Authentication" section, added the Auth Modes table and
current three-endpoint contract) and `.env.example` (`VITE_AUTH_MODE` now
commented out/opt-in instead of defaulting to `demo`).
**Next exact step:** Task 9 — data dictionary, runbook, OpenAPI update,
`DEMO_CREDENTIALS.md` clarification, and the final combined backend+frontend
verification gate including a live browser QA attempt.

### 2026-07-27 19:22 +08:00 — Task 9: documentation and full verification gate

**Documentation completed:**
- `docs/api/openapi.yaml` — added the three auth routes, a `bearerAuth`
  security scheme, `LoginRequest`/`AuthResource`/`UserResource` schemas, and
  `NoStorePrivate`/`Unauthenticated` shared components. Fixed one OpenAPI 3.1
  issue caught by Redocly: `nullable: true` (an OpenAPI 3.0-ism) on
  `expires_at` isn't valid JSON Schema for 3.1 — corrected to
  `type: ["string", "null"]`. Redocly lint and a PyYAML structural parse both
  pass (4 paths, 9 schemas).
- `docs/data-dictionary/identity-foundation.md` — all four tables, exact
  types/constraints, the token-expiration provisional-value note, and the
  seeded-data summary.
- `docs/runbooks/mariadb-local.md` — full setup/migrate/seed/reset procedure,
  leading with the known instability warning and the table-level-grant
  workaround so a future session doesn't rediscover it the hard way.
- `docs/testing/DEMO_CREDENTIALS.md` — added an explicit comparison table
  against `SEEDED_IDENTITIES.md` and corrected the local-setup steps to
  require `VITE_AUTH_MODE=demo` (no longer the default).
- Root `README.md` — corrected a now-actively-wrong line ("Do not run
  migrations against the bundled XAMPP MariaDB instance") and updated Current
  Status/Environment Baseline to reflect ADR 0007 and the real auth slice.

**Live browser QA — the first successful browser session in this project's
history.** Every prior session recorded "No browser is available." This
session's Playwright MCP connection worked. Ran real Laravel (`php artisan
serve`, port 8100) and Vite (port 5173, matching `CORS_ALLOWED_ORIGINS`)
servers against the live MariaDB database and drove the actual browser:
- Fresh `/login` in the default (`api`) mode: real institutional copy, no
  demo disclaimer, form enabled, pointing to `SEEDED_IDENTITIES.md`.
- Signed in with a real seeded identity (`dean.seed@grc.test`, then
  `registrar-staff.seed@grc.test`) — genuine `POST /api/v1/auth/login`
  against MariaDB, zero console errors, landed on `/portal` with the correct
  role, display name, and role-filtered module set for each.
- **Full page reload while authenticated correctly restored the session** via
  the stored bearer token and a live `GET /api/v1/auth/me` call — no
  re-login required, zero console errors.
- Mobile viewport (390×844): screenshot confirmed clean layout, no overflow,
  correct copy.
- Sign-out correctly cleared the session and revoked the token.
**Found and fixed one real content bug via this live pass:** the portal
shell's sidebar hardcoded a "Demo portal" badge (and matching mobile-sheet
description and storage-unavailable warning text) regardless of auth mode,
so a genuinely authenticated user would have been told they were in a demo.
Made all three mode-aware (`isDemo` from `authMode`): `api`/`disabled` now
show "Preview portal" and non-demo wording; demo mode is byte-identical to
before. Left the separate "Workflow and authorization APIs are not connected
in this preview" module alert untouched — that one remains accurate in every
mode, since business workflow endpoints genuinely aren't implemented yet.
**Found and fixed a second real gap via a test that initially claimed the
wrong thing:** writing a test for "API mode warns when storage is
unavailable" revealed that `auth-context.tsx` never surfaced whether the
API gateway's token write actually succeeded — `storageAvailable` was
hardcoded effectively-true for API mode, so a user in a browser with
`localStorage` disabled would get no warning that their session cannot
survive a refresh. Added an optional `persistenceAvailable()` method to the
`DemoAuthGateway` interface, implemented in `api-auth-gateway.ts` by tracking
the last `tokenStore.write()` result, and wired it into `auth-context.tsx`'s
sign-in path. Four new tests cover both the fixed portal-shell copy and the
gateway method directly.
**Known non-blocking finding, not fixed:** in a real `BrowserRouter` (not
jsdom), `PortalShell`'s pre-existing sign-out handler (`await navigate("/",
...); signOut()`) sometimes lands on `/login?returnTo=/portal` instead of
`/` for the API-mode async sign-out path — `navigate()` from `useNavigate()`
isn't a reliably awaitable commit signal outside a data router, so the two
calls can interleave differently than in jsdom. The user still ends up on a
fully functional page (a working login form, arguably reasonable UX since
re-authenticating returns them to where they were) with no data or security
issue and the token is genuinely revoked either way. Not part of this
slice's scope (auth backend/gateway, not portal-shell navigation
architecture); flagged here rather than risking an unreviewed architectural
change under time pressure.
**Test-environment hygiene:** a stray `.env.local` created for the live
browser test (pointing `VITE_API_BASE_URL` at the temporary port 8100)
leaked into two Vitest runs, breaking `health-service.test.ts`'s hardcoded
URL assertion. Removed before the final gate; confirmed 174/174 clean
afterward. Also found and stopped an unrelated stale `vite` process serving
the **main checkout** (not this worktree) that had been idly holding port
5173 from an earlier, unrelated session — consistent with this repo's own
documented history of needing exactly this kind of cleanup.
**Final verification, everything from a clean state:**
- Backend: `composer test` 70/70 (250 assertions), `format:check`,
  `analyse` (32 files, Larastan level 8, 0 errors), `audit --locked` (0
  advisories), `route:list` shows exactly the 4 intended routes.
- Frontend: `npm test` 174/174, `format:check`, `lint` (0 warnings),
  `lint:fast`, `typecheck`, `build` (succeeds; one chunk-size advisory
  notice, not an error), `audit --audit-level=moderate` (0 vulnerabilities).
- OpenAPI: Redocly lint clean, PyYAML parse clean.

**All nine tasks of
`docs/superpowers/plans/2026-07-27-mariadb-identity-sanctum-auth.md` are now
complete.** The identity foundation runs on the existing XAMPP MariaDB
instance (ADR 0007), and the portal authenticates for real against it via
Sanctum bearer tokens — the original goal driving this entire session's
pivot away from the abandoned isolated MySQL 8.4 plan.
**Not done, out of scope for this slice:** CI, authorization Policies beyond
role-filtered navigation, business workflow endpoints, password reset, and
every PRD §17 institutional policy confirmation (including the real token
expiration value). No commit, push, or merge back to `main` was made —
`AGENTS.md` requires explicit authorization for that, which has not been
given in this session.

### 2026-07-27 20:27 +08:00

**Goal:** Turn "what's next on the PRD checklist" into the first concrete
sub-project of PRD §5.1 (Pre-enrollment schedules) and implement it.
**Decomposition:** Process 1.0 spans 10 FRs, 8 tables, and 4 independent
subsystems plus a forecast requirement blocked on Process 4.0 — too large for
one slice. Brainstormed and chose the authorization foundation and reference
data as sub-project 1: it unblocks every later sub-project (PRD §9.4 requires
a Policy or explicit authorization decision on every resource endpoint, and
none existed yet) and both `programs`/`academic_terms` were empty tables with
no models.
**Completed (branch `feat/authz-foundation-reference-data` off `main`, worked
in place per this session's configuration, not a worktree):**
- `App\Domain\Organization\ProgramStatus`/`AcademicTermStatus` — provisional
  status enums (PRD §17 unconfirmed vocabulary) with an
  `isVisibleToLearners()` predicate.
- `UserRole::isLearnerScoped()` — single source of truth for which three
  roles (student, faculty, accounting staff) receive filtered results versus
  the six planning roles.
- `Program`/`AcademicTerm` Eloquent models with full `@property` docblocks
  and a `scopeVisibleTo()` query scope on each.
- Authorization stack: `AuthorizesRequests` restored on the base
  `Controller` (Laravel 12 had dropped it), `EnsureUserHasRole` middleware
  registered as the `role` alias (built now, no production route consumes it
  yet — see ADR 0008), `ProgramPolicy`/`AcademicTermPolicy`.
- `GET /api/v1/programs` and `GET /api/v1/academic-terms` — readable by every
  role, row set filtered by `visibleTo()`, `{"data": [...]}` envelope,
  `Cache-Control: no-store, private`.
- `ProgramSeeder`/`AcademicTermSeeder` — synthetic catalog (3 programs, 3
  terms), each including one non-learner-visible row.
- Docs: ADR 0008, `docs/api/openapi.yaml` (2 new paths + schemas, Redocly
  clean), `docs/data-dictionary/identity-foundation.md`,
  `docs/testing/SEEDED_IDENTITIES.md`, this file.
**Bugs found by tests, not by inspection:**
- `withResponse()` on `ProgramResource`/`AcademicTermResource` never fired —
  dead code, because `JsonResource::collection()` calls `withResponse()` on
  the wrapping `AnonymousResourceCollection`, not each item. See Failure and
  Recovery Record above for the fix (set the header on the controller's
  `JsonResponse` directly).
- A plain `curl` request (no `Accept: application/json`) to any
  `auth:sanctum` route — including the pre-existing `/api/v1/auth/me` and
  `/api/v1/auth/logout`, not just the two new endpoints — crashed with a 500
  `RouteNotFoundException: Route [login] not defined`, instead of the
  documented 401. Root cause: Laravel's `ApplicationBuilder` unconditionally
  defaults unauthenticated guests to `redirectGuestsTo(fn () => route('login'))`;
  this is a JSON-only API with no `login` named route. This predates this
  slice — every prior feature test used `getJson()`/`postJson()`, which set
  the `Accept` header automatically and never exercised the crash path.
  Fixed with `$middleware->redirectGuestsTo(fn () => null)` in
  `bootstrap/app.php`; confirmed the new
  `tests/Feature/Auth/UnauthenticatedNonJsonRequestTest.php` fails with the
  exact same error when the fix is temporarily reverted, and passes with it
  restored.
**Verification:** `composer test` 110/110 (325 assertions, up from 70/250),
`format:check`, `analyse` (Larastan level 8, 0 errors), `audit --locked` (0
advisories), Redocly OpenAPI lint clean, `route:list` exactly 6 routes, and a
**live HTTP proof** against a seeded dev database and a running
`php artisan serve`: `student.seed@grc.test` and `chair.seed@grc.test` hit
the identical `GET /api/v1/programs` and `GET /api/v1/academic-terms` URLs
and received genuinely different row counts and status sets; a tokenless
request returned 401 `UNAUTHENTICATED` both with and without an `Accept`
header.
**Not done, out of scope for this sub-project:** writes to programs/terms;
curriculum catalog, faculty input, section planning, approval workflow,
demand forecast, and audit logging (the remaining five Process 1.0
sub-projects); every frontend change; CI. No commit, push, or merge was
made — `AGENTS.md` requires explicit authorization, not yet given.

### 2026-07-27 21:15 +08:00

**Goal:** Implement the next PRD §5.1 sub-project — the curriculum catalog
(FR-SCH-001, FR-SCH-002) — and update the checklist after.
**Completed (branch `feat/curriculum-catalog` off `main`, worked in place):**
- Four migrations (`subjects`, `curricula`, `curriculum_subjects`,
  `subject_prerequisites`) — the first in this codebase to use foreign keys,
  with explicit `restrictOnDelete()`/`cascadeOnDelete()` per PRD §10.6.
  Required granting `grc_migrator`/`grc_test` table-level `CREATE`+DDL+DML on
  all 4 new tables first (health-checked `mysql.db` et al. before granting,
  per the MariaDB-instability memory; zero incidents).
- `App\Domain\Curriculum\SubjectStatus`/`CurriculumStatus` — provisional
  enums, same discipline as ADR 0008.
- `App\Domain\Curriculum\PrerequisiteCycleDetector` — a pure DFS cycle check
  over `{subject_id, prerequisite_subject_id}` edges, independent of
  Eloquent/persistence, covered by 8 unit tests (self-loop, direct, and
  transitive cycles; linear chains, shared prerequisites, and diamond graphs
  all correctly pass as non-cyclic).
- `Subject`/`Curriculum`/`CurriculumSubject`/`SubjectPrerequisite` models,
  `SubjectPolicy`/`CurriculumPolicy` (the latter adds `create`/`update`
  restricted to `program_chair`), and `GET /api/v1/subjects`,
  `GET/POST /api/v1/curricula`, `PATCH /api/v1/curricula/{curriculum}`.
- `POST`/`PATCH /curricula` are the **first production route** gated by the
  `role` middleware built (but unused) in the previous slice — restricted to
  `program_chair`, matching the frontend's existing module ownership.
- Full-replace write semantics: `StoreCurriculumRequest`/
  `UpdateCurriculumRequest` validate a nested `subjects` array and run the
  cycle detector in `withValidator()`; `SynchronizeCurriculumSubjects`
  deletes and recreates a curriculum's placements/prerequisites in one
  transaction. See ADR 0009 for why (PRD §8.4 lists no separate endpoints for
  the two child tables).
- Docs: ADR 0009, `docs/data-dictionary/curriculum-catalog.md`, 2 new OpenAPI
  paths + a `Forbidden` response + 10 new schemas (Redocly clean), this file.
**Bug caught by Larastan, not by tests:** `CurriculumResource`'s nested
array-shape docblocks declared `list<...>` but `Collection::map()->all()`
doesn't guarantee a list to PHPStan even when keys are sequential. Fixed with
`array_values(...)`, which has an explicit "always returns a list" stub —
`Collection::values()->all()` alone did not satisfy Larastan.
**Verification:** `composer test` 162/162 (451 assertions, up from 110/325),
including the PRD's own acceptance criterion verbatim ("A Program Chair
cannot create a prerequisite cycle") for both a direct and a transitive
cycle; `format:check`; `analyse` (Larastan level 8, 0 errors); `route:list`
exactly 10 routes; migration rollback/reapply clean; Redocly OpenAPI lint
clean; and a **live HTTP proof** that the automated suite alone could not
have caught (see Failure and Recovery Record for the `grc_app` grant gap it
surfaced): as `chair.seed@grc.test`, created a curriculum with a valid
prerequisite (201), then had a direct two-subject cycle rejected (422, exact
PRD wording); as `student.seed@grc.test`, the same `POST` was rejected (403)
while `GET` succeeded and correctly hid the still-`draft` curriculum, then
correctly showed it once a `PATCH` (full subject-list replace) set its
status to `active`.
**Not done, out of scope for this sub-project:** faculty input, section
planning, approval workflow, demand forecast, and audit logging (the
remaining four Process 1.0 sub-projects); every frontend change; CI.

### 2026-07-28 01:00 +08:00 — Untracked scaffold discovered and audited; schema foundation landed

**Goal:** Push `main` to `origin` (routine follow-up from the previous
session), then continue with whatever the user directed next.
**Discovery:** `git status` showed 43 untracked files and a modified
`DatabaseSeeder.php` with zero git history — not part of anything this
session or the prior one had done. A full read-through (all 13 migrations,
13 models, 10 enums, 4 seeders, 2 tests) found genuinely careful,
convention-matching work: correct FK semantics, PRD §-citations, consistent
PROVISIONAL-vocabulary flags, and one clever piece of schema design (below).
It covered two unrelated things — the three remaining PRD §5.1 sub-projects
(faculty input, section planning, approval workflow) and a completely
separate enrollment-records domain (`student_profiles` + 8 PRD §10.3 tables)
belonging to a distinct, later phase. See Failure and Recovery Record for the
full investigation (event log checks, the already-applied migrations, the
transient MariaDB connection refusal).
**User decisions (via plan mode, `backgrounded-inherited-treasure.md`):**
adopt and properly finish the scaffold rather than discard or ignore it; land
the enrollment-records domain as schema-only (tested, documented, no API)
since it belongs to a future phase; work through four sequential branches
(schema foundation → faculty input → section planning → approval workflow)
reviewed as one plan but merged one at a time.
**Completed (branch `feat/schedule-and-enrollment-schema-foundation` off
`main`, worked in place):**
- MariaDB safety check (`CHECK TABLE` on the five privilege tables, Event Log
  clean) then 39 table-level `GRANT` statements — `CREATE`+DDL+DML to
  `grc_migrator`/`grc_test`, DML to `grc_app` once tables existed — across all
  13 new tables, zero incidents. `FLUSH PRIVILEGES` deliberately omitted this
  time (unnecessary, and part of one of the two prior crash incidents).
- Confirmed the 13 tables were already migrated (batch 3) by whatever process
  wrote them; verified schema correctness directly (`SHOW CREATE TABLE`)
  rather than re-migrating.
- 24 new unit tests (11 domain enums, 13 models) and 4 new migration-
  constraint test files covering the 6 tables the found tests didn't reach.
- Fixed one real gap: `StudentProfile.year_level` now casts to `integer`,
  matching every other tiny/small-int column in this codebase — caught by a
  new unit test, not assumed.
- `docs/data-dictionary/enrollment-records.md` (new); corrected
  `curriculum-catalog.md`'s now-stale "no seeder ships" note, since
  `SubjectSeeder`/`CurriculumSeeder` were part of the same found batch.
- This `PROGRESS.md` entry, plus updated Database/Tests/Decisions sections
  and a checklist note that "Enrollment and digital advising" has schema-only
  groundwork but stays unchecked.
**Verification:** `composer test` 248/248 (640 assertions, up from 162/451);
`format:check`; `analyse` (Larastan level 8, 106 files, 0 errors); `audit`
clean; `route:list` still exactly 10 routes (schema-only task, no new
endpoints); Windows Event Log re-checked clean after every privilege write
and after migrating.
**Not done, deliberately deferred to the next three branches:** any Policy,
Resource, Controller, or route for faculty input, section planning, approval
workflow, or the enrollment-records domain; every frontend change; CI.

### 2026-07-28 01:45 +08:00 — Task 2: Faculty Input API (FR-SCH-003)

**Goal:** Second of the three remaining PRD §5.1 sub-projects from the
approved plan — build the API layer on top of the schema landed in the
previous task.
**Completed (branch `feat/faculty-input` off `main`, worked in place):**
- `FacultyAvailability`/`FacultySubjectPreference` gained a **new**
  `scopeVisibleTo()` shape: own-record, not status-based (neither table has
  a status column). Learner-scoped roles (in practice only `Faculty`) see
  `WHERE professor_id = $user->id`; planning roles see every professor's
  rows unfiltered.
- `FacultyAvailabilityPolicy`/`FacultySubjectPreferencePolicy`: `viewAny`
  true for everyone (the scope filters rows); `view` follows the
  visibility rule; `create` requires the `Faculty` role; `update`/`delete`
  require **both** the `Faculty` role **and** row ownership — a new
  two-condition shape, since same-role professors must not edit each
  other's rows.
- 8 new routes: `GET/POST /api/v1/faculty-availabilities`,
  `PATCH/DELETE /api/v1/faculty-availabilities/{facultyAvailability}`, same
  shape for `faculty-subject-preferences`. Writes gated `role:faculty`;
  `professor_id` is forced server-side to the authenticated user's ID, never
  accepted from the request body. These are the first `DELETE` endpoints in
  this API.
- The two `faculty_subject_preferences` composite-uniqueness rules
  (subject-per-term, rank-per-term) are enforced pre-flight with
  `Rule::unique()->where()->ignore()` rather than a custom `withValidator()`
  hook — simpler than the curriculum catalog's cycle detector because the
  underlying rule is plain uniqueness, not graph logic; same outcome (clean
  422, not a raw SQL error).
- Docs: `docs/data-dictionary/faculty-input.md`; OpenAPI gained 1 new tag,
  8 new paths, 10 new schemas.
**Two things caught before completion, not after:**
- An `openapi.yaml` edit matched its `old_string` one property short of the
  actual file, splitting the pre-existing `minimum_grade` property's
  `type`/`maxLength` apart. `@redocly/cli lint` failed immediately with a
  YAML parse error; fixed and re-linted clean. See Failure and Recovery
  Record.
- Starting `php artisan serve` for live verification found a pre-existing
  listener on port 8000 (two `php.exe`, running ~40 minutes already) that
  this session never started — a possible concurrent process against the
  same repository/database. Identified and stopped only my own new pair by
  matching exact process start times; left the pre-existing pair and one
  other unrelated `php.exe` untouched. See Failure and Recovery Record.
**Verification:** `composer test` 278/278 (752 assertions, up from
248/640), `format:check`, `analyse` (Larastan level 8, 116 files, 0
errors), `audit` clean, `route:list` exactly 18 routes, OpenAPI lint clean.
Given the port-8000 ambiguity, relied on the automated suite's real
HTTP-level coverage (Sanctum tokens through `postJson`/`patchJson`/
`deleteJson` against the real named routes) rather than an additional
manual `curl` session for this slice.
**Not done, out of scope for this sub-project:** section planning, approval
workflow (the next two branches); the enrollment-records domain's API layer
(deliberately out of scope per the schema-foundation task's decision); every
frontend change; CI.

### 2026-07-28 05:03 +08:00 — Task 3: Section Planning API (FR-SCH-004, FR-SCH-005)

**Goal:** Third of the three remaining PRD §5.1 sub-projects from the
approved plan.
**Completed (branch `feat/section-planning` off `main`, worked in place):**
- `App\Domain\Scheduling\ScheduleDayParser` — parses the `schedule_days`
  shorthand already seeded (`"MWF"`, `"TTh"`, `"Sat"`) into ISO-8601
  day-of-week integers, greedy and longest-token-first so `Th`/`Sat`/`Sun`
  aren't swallowed by single-letter checks; stops at the first unrecognized
  character rather than guessing.
- `App\Domain\Scheduling\SectionConflictDetector` — same pure,
  persistence-free shape as `PrerequisiteCycleDetector`. Flags a conflict
  only for same-professor, same-term, shared-day, overlapping-time
  double-booking (half-open interval check, so back-to-back slots don't
  conflict). Deliberately does **not** check room conflicts or
  faculty-availability matching — see ADR 0010 for why.
- `SectionStatus::isVisibleToLearners()` and `Section::scopeVisibleTo()`,
  matching the `Curriculum`/`Subject` pattern exactly.
- `SectionPolicy`, `Store`/`UpdateSectionRequest` (composite-unique
  `section_code` via `Rule::unique()->where()`, conflict check in
  `withValidator()`), `SectionResource` (includes a display-only
  `remaining_seats`), `SectionController` (index/store/update — no delete,
  matching curriculum's shape). 3 new routes, writes gated
  `role:program_chair`.
- Docs: ADR 0010, `docs/data-dictionary/section-planning.md`; OpenAPI gained
  1 tag, 2 paths, 4 schemas.
**Caught before completion, not after:**
- Larastan found 4 errors on the first run — a nullable-field-narrowing gap
  and a recurrence of the `Collection::map()->all()` vs `list<...>` issue
  from the curriculum-catalog slice. Both fixed using the same patterns
  already established. See Failure and Recovery Record.
- A `SectionPolicyTest` test tripped a duplicate-email unique-constraint
  violation from calling its own test helper twice with the same role.
  Fixed by reusing one user instance.
- Mid-way through the first full test run, MariaDB stopped entirely with no
  crash logged — paused and asked the user rather than restarting it myself,
  given the crash history; user confirmed they'd started it, rerun was
  clean. See Failure and Recovery Record for the full timeline.
**Verification:** `composer test` 312/312 (832 assertions, up from
278/752), `format:check`, `analyse` (Larastan level 8, 123 files, 0 errors),
`audit` clean, `route:list` exactly 21 routes, OpenAPI lint clean.
**Not done, out of scope for this sub-project:** approval workflow (the
final branch); the enrollment-records domain's API layer; every frontend
change; CI.

### 2026-07-28 05:24 +08:00 — Task 4: Approval Workflow API (FR-SCH-007 through FR-SCH-009); PRD §5.1 unblocked scope complete

**Goal:** Final of the four tasks in the approved plan — the last of the
three remaining PRD §5.1 sub-projects.
**Completed (branch `feat/schedule-approval-workflow` off `main`, worked in
place):**
- `App\Actions\Scheduling\TransitionScheduleProposal` — applies one of six
  transitions (`dean_approve`, `dean_return`, `executive_approve`,
  `executive_return`, `publish`, `close`), records `decided_by`/`decided_at`/
  `decision_reason`, and — only for `publish` — bulk-transitions every
  `planned` section in the proposal's term to `published`, all in one
  `DB::transaction()`. No new foreign key between `schedule_proposals` and
  `sections`; see ADR 0011 for why a term-scoped bulk update is the right
  call here.
- `ScheduleProposalPolicy` — a new authorization shape: four abilities
  (`approveAsDean`, `approveAsExecutive`, `publish`, `close`) instead of the
  usual `create`/`update` pair, since one `PATCH` route serves six
  role-specific transitions. `ScheduleProposalController` resolves which
  ability applies from the request's `action` field — the first write route
  in this API with **no** `role:` middleware at all.
- `Store`/`UpdateScheduleProposalRequest` — the one-active-proposal-per-term
  guard (application-level, same reasoning as `enrollments`' generated
  column), the current-status precondition per action, and
  `decision_reason` required exactly for the two return actions.
- 3 new routes; `ScheduleProposalResource`; ADR 0011;
  `docs/data-dictionary/approval-workflow.md`; OpenAPI gained 1 tag, 3
  paths, 4 schemas.
**Caught before completion, not after:**
- A genuine Laravel/Sanctum testing quirk: chaining four different
  authenticated users within one test method (simulating the real
  chair→dean→executive→registrar workflow) hit a guard-caching issue where
  the second `withToken()` swap silently kept the *first* request's user.
  `forgetGuards()` didn't fix it. Resolved by testing each transition as its
  own single-actor test — the same structure every other endpoint test in
  this session already uses — rather than fighting the framework further.
  See Failure and Recovery Record and ADR 0011's testing note.
- Two test-setup mistakes caught by the run itself: a proposal precreated in
  the wrong status for the action under test, and a visibility test hitting
  `academic_terms`' unique constraint by creating two terms with identical
  `(school_year, semester)`. Both fixed at the test, not the application
  code — see Failure and Recovery Record.
**Verification:** `composer test` 335/335 (898 assertions, up from
312/832), `format:check`, `analyse` (Larastan level 8, 129 files, 0
errors), `audit` clean, `route:list` exactly 24 routes, OpenAPI lint clean.
**Not done, deliberately out of scope:** the enrollment-records domain's
API layer (a future PRD phase, per the schema-foundation task's decision);
demand forecast (FR-SCH-006, blocked on Process 4.0); audit logging
(FR-SCH-010, cross-cutting, its own future slice); every frontend change;
CI. This completes every currently-unblocked PRD §5.1 sub-project.

### 2026-07-28 06:28 +08:00 — Student Profile Foundation (PRD §5.2, DFD 2.1); first Process 2.0 sub-project

**Context:** Between the previous entry and this one, `.github/workflows/ci.yml`
(4 jobs) was built and pushed to `main`; `backend`/`frontend`/`docs` are
confirmed green on two real runs, `ml-service` still fails at its `pytest`
step with two hypotheses ruled out (see Failure and Recovery Record) and
was **paused per explicit user direction** to move on to the next PRD phase
rather than keep guessing. That redirect led here: PRD §5.2 (Process 2.0,
roadmap Phase 3) decomposes into four DFD subprocesses; this slice covers
only 2.1 (Authenticate and Read Profile), naming eligible-subject-pool
(2.2/2.3) and enrollment-submission (2.4) as the next two.
**Completed (branch `feat/student-profile-foundation` off `main`, worked in
place):**
- `App\Actions\Identity\ProvisionStudent` — one `DB::transaction()` creating
  the `User` (role `student`, status `active`) and `StudentProfile`
  (`admission_status: admitted`, `academic_standing: good`) together; a
  profile never exists without its account, or vice versa.
- `StudentProfilePolicy` — a fourth authorization shape: `view()` is
  own-record only (`$user->id === $profile->user_id`) with **no**
  planning-role broad visibility at all, unlike every other resource in
  this API. `create()` restricted to `admission_staff`.
- `StoreStudentProfileRequest` — unique email/student_number,
  `withValidator()` rejects a curriculum that doesn't belong to the
  submitted program (a check nothing previously enforced).
- `StudentProfileController` — `store()` (`POST /api/v1/student-profiles`,
  `role:admission_staff`, 201) and `show()` (`GET /api/v1/student-profile`,
  no path parameter, resolves the caller's own profile like `auth/me`, 404
  not 500 if none exists).
- 2 new routes (26 total); `StudentProfileResource` (exact key set, no
  password); `docs/data-dictionary/student-profile-foundation.md`; OpenAPI
  gained 1 tag ("Student Records"), 2 paths, 3 schemas.
**Caught before completion, not after:**
- The Sanctum guard-caching quirk (see the Approval Workflow entry above)
  recurred a second time: `test_a_student_can_read_their_own_profile`
  originally chained an Admission Staff provisioning call and a different
  student's login+read call in one test method, surfacing as an unexpected
  404 (347/348 passed) rather than a 403. Fixed the same way — create the
  `User`+`StudentProfile` directly via Eloquent so the test authenticates
  as exactly one user via HTTP. Two independent recurrences now confirm
  this is a structural constraint of this suite, not a one-off.
- `composer format:check` failed on first run (unused import and spacing in
  `StoreStudentProfileRequest`, import order and a trailing comma in the two
  new test files); fixed by running `composer format` (Pint auto-fix) and
  re-verifying the full suite still passed afterward.
**Verification:** `composer test` 348/348 (939 assertions, up from
335/898), `format:check`, `analyse` (Larastan level 8, 134 files, 0
errors), `audit` clean, `route:list` exactly 26 routes, OpenAPI lint clean.
Merged to `main` (fast-forward), re-verified 348/348 on merged main, branch
deleted. Live HTTP proof against a temporary `php artisan serve` on
127.0.0.1:8100 (port 8000 left alone — an unrelated pre-existing process was
already listening there, same ambiguity as the section-planning/
approval-workflow slices): Admission Staff provisioned two real students
(201 each), each logged in and read only their own profile (200, never each
other's), and Faculty attempting to provision was rejected (403). All
issued tokens logged out afterward.
**Not done, deliberately out of scope:** eligible subject pool and
enrollment submission (the next two Process 2.0 sub-projects, named above);
all of Process 3.0 (§5.3); student self-service profile editing; password
reset endpoints; every frontend change; the ml-service CI job (still
paused).

---

## Phase 5 — Portals over Existing APIs, task-by-task record

Built on an isolated branch/worktree (`phase-5-portal-workspaces`, base
`f78da68`) across nine tasks, each implemented, independently reviewed, and
where findings surfaced, remediated and re-reviewed before acceptance. This
entry preserves the full RED/GREEN/review trail that `PROGRESS.md` now
summarizes in one paragraph per phase.

**Task 1 — audited faculty directory API.** Added Program Chair-only
`GET /api/v1/faculty-members`, returning active Faculty users only, in
deterministic `name` then `id` order, via a five-field Resource
(`type`, `id`, `name`, `status`, `status_label`) with no email. Records
`faculty_directory.list_viewed` with only `result_count` in the same
transaction as the query, so a write failure yields no directory payload.
Focused `FacultyMembersEndpointTest|AuditVocabularyTest|ApiSurfaceTest` gate:
24 tests / 147 assertions. API inventory: 29 → 30 routes.

**Task 2 — shared API client, error mapping, UI primitives.** Added
authenticated `PATCH`/`DELETE` JSON helpers, `ApiClientError.fieldErrors`,
and React Hook Form 422 field mapping. Installed and mounted Sonner once;
added Table/Select/Dialog/Alert Dialog/Pagination primitives under the
existing shadcn alias. Focused client/form tests: 14 tests; full frontend
suite: 16 files / 150 tests. Independent review: accept, no findings.

**Task 3 — portal shell, reference data, notifications, module registry.**
Added strict Zod schemas/services/hooks for academic terms, programs,
subjects, and private notifications. Shell/overview show only the
`status: active` term or an honest no-active-term fallback. Live notification
Sheet: unread count/filter, pagination, idempotent mark-as-read PATCH. Added
the explicit 13-ID `phaseFiveModuleRegistry`. Focused: 6 files / 70 tests;
full suite: 19 files / 160 tests.
**Remediation (accepted):** independent review found identity-free private
query keys could let a second account on the same browser reuse the first
account's cached notification response. Notification and authenticated
reference-data query keys now include `session.userId` and are disabled while
anonymous; a User A → logout → User B regression proves isolation. Re-review:
accept, no findings.

**Task 4 — Admission Staff provisioning.** One three-step workspace serving
`student-accounts`, `admission-status`, `credential-issuance`. Submits only
the seven accepted provisioning fields; status step shows the API-guaranteed
`Admitted`/`Good` read-only (no invented update API). A browser CSPRNG
generates a 20-character one-time temporary credential that never enters
storage, logs, form state, or query caches; displayed only in the success
receipt, clipboard-copyable, cleared on close. Focused: 4 files / 9 tests;
full suite: 22 files / 166 tests.
**Remediation (accepted):** curriculum parsing corrected to the backend's
`archived` status literal; provisioning service now strictly parses its
seven-field request before fetch; changing `program_id` clears the dependent
curriculum field in RHF state; added clipboard success/denial and
retry-gets-a-fresh-credential regressions. Focused: 4 files / 12 tests; full
suite: 23 files / 170 tests. Re-review: accept, no findings.

**Task 5 — Faculty availability, preferences, teaching schedule.** Added
CRUD services/hooks for `/faculty-availabilities` and
`/faculty-subject-preferences`, plus a responsive (desktop table / mobile
card) teaching schedule reading only parsed, API-scoped sections — no roster
or grade endpoint call. Focused: 4 files / 23 tests; full stack: backend 517
tests / 1,956 assertions, frontend 26 files / 179 tests.
**Remediation (accepted) — real backend privacy fix:** `GET /api/v1/sections`
had exposed every published/closed section to *every* Faculty user, not just
their own. `Section` collection scope and `SectionPolicy::view()` now both
constrain Faculty to their own `professor_id`; Student/Accounting status-only
and planning-role full visibility unchanged. Re-review: accept, no findings;
backend review gate 18 files / 52 tests, frontend review gate 5 files /
27 tests.

**Task 6 — Program Chair curriculum and prerequisites.** `curriculum` and
`subjects-prerequisites` dispatch to one full-replace curriculum editor.
Create sends `program_id` + full graph; update omits immutable `program_id`
while preserving every placement/prerequisite. Client-side duplicate/
self-prerequisite/cycle checks sit beside backend cycle-422 feedback. Focused:
4 files / 10 tests; full suite: 29 files / 186 tests.
**Remediation (accepted):** placement now requires a catalog selection and
exposes per-placement year level/semester/required controls; dirty-curriculum
switching shares the same accessible discard confirmation as "New"; create
adopts the returned ID so the next save PATCHes; response envelope parsing
strictly rejects unexpected top-level properties. Focused: 4 files / 15
tests; full suite: 29 files / 191 tests. Re-review: accept, no findings.

**Task 7 — Program Chair sections, faculty assignment, proposals.**
`sections-schedules` validates term/subject dependencies, schedule-day
shorthand, end-after-start times, positive capacity, and creates/fully
replaces sections. `faculty-assignment` lists only active directory
identities (client schema rejects an undeclared email field), shows matching
availability/preference context, PATCHes the complete section. `schedule-
proposals` creates drafts only — no approval/publish/close controls exposed
to the chair. Focused: 5 files / 9 tests; full suite: 34 files / 200 tests.
**Remediation (accepted):** fixed a second-term Section create silently
resetting to the previously-watched term instead of the just-selected one;
added in-place retry for failed reference queries across all three
workspaces; corrected the assignment-conflict fixture to the backend's actual
`schedule_days` 422 field. Focused: 5 files / 13 tests; full suite: 34 files /
204 tests. Re-review: accept, no findings.

**Task 8 — Dean, Executive Director, Registrar Head.** Dean/Executive receive
a shared schedule-decision component gated to their own legal actions only
(`availableScheduleActions(role, proposal)` mirrors the backend's exact
status/role matrix); a reason is required only for `dean_return` and
`executive_return`; each transition requires an explicit confirm step and
disables duplicate submission while pending. Executive Director's master
schedule and Registrar Head's paginated/filterable audit log (never rendering
actor name or email) round out the task. Focused: 5 files / 11 tests; full
suite: 38 files / 212 tests.
**Remediation (accepted) — real backend privacy fix:** Executive Director had
fallen through the generic non-learner branch, so unpublished
(planned/closed/cancelled) sections reached the private frontend cache. Both
the `Section` collection scope and the direct-access policy now require
`status === 'published'` for Executive Director. Audit action/entity filters
now mirror the backend/OpenAPI enum vocabulary exactly. Focused backend: 15
tests / 50 assertions; focused frontend: 3 files / 9 tests. A transient
single-file Faculty timeout during this task's verification did not
reproduce in isolated reruns or two subsequent clean full-suite passes (38
files / 215 tests each); no fake timers, leaked global mocks, or shared-state
issue was found — treated as parallel-worker contention, not a defect.
Re-review: accept, no Critical or Important findings. Root independently
re-verified backend 519 tests / 1,964 assertions.

**Task 9 — reconciliation and final gate**, carried out by the takeover
agent after the user authorized continuation (see `PROGRESS.md` Phase 5
entry for the consolidated result): added the carried-forward
proposal/section cache-invalidation regression and strengthened the
module-registry boundary test to check every catalog module outside the
Phase 5 registry, not just one. Confirmed the frontend full-suite parallel
run is unreliable on this specific low-memory (~6 GB free) Windows dev
machine — different files fail each parallel run (2 to 27 tests observed
across five runs) but every one of those tests passes individually, and
`npx vitest run --no-file-parallelism` passed clean at 38 files / 216 tests
twice. This is a machine-resource artifact, not a code defect; documented as
an Operational Caution rather than "fixed" by changing shared vitest config,
since CI's GitHub Actions runners are unaffected (already green) and Task 9's
scope is verification, not test-infrastructure rework.

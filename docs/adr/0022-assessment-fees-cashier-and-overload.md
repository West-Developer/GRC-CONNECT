# ADR 0022 — Assessment & fees, guided Cashier flow, queue policy, overload approval, and connected professors

**Status:** Accepted
**Date:** 2026-08-05

## Context

ADR 0021 closed the enrollment cycle end to end — submit, approve, pay,
attend, grade — but a codebase exploration after that slice found five
further gaps between "the cycle runs" and "every PRD-documented
sub-process actually has a real value behind it":

1. **No assessment or fees anywhere.** Zero `tuition|fee|assessment`
   matches in `backend/app` or `frontend/src`. `payments.amount` was
   nullable and manually typed by the cashier with nothing to compare it
   against, even though PRD §5.3 process 3.3 (`PRD.md:332`) specifies the
   Registrar approval step "computes the approved assessment," and
   "assessments" is listed under ENROLLMENT RECORDS (`PRD.md:399`).
2. **Four Cashier nav modules, one undifferentiated screen.**
   `payment-queue`, `serving-number`, `payment-confirmation`, and
   `com-finalization` all rendered the same component with only the
   heading string changed. No NOW SERVING display (FR-FIN-006), and the
   queue table and payment table had no relationship — nothing forced the
   cashier to call a ticket before confirming a payment.
3. **Accounting visibility collapsed the moment a payment was confirmed.**
   `Enrollment::scopeVisibleTo` only showed Accounting `pending_payment`
   enrollments — confirm one, and it vanished. No history, no daily
   collection total, no reprint.
4. **A student could not withdraw.** The backend and
   `useCreateWithdrawalRequestMutation` were both complete and correct —
   zero components called the hook. The Registrar side of the workflow
   worked; nothing on the student side could start it.
5. **`max_regular_units`/`overload_max_units` were mechanism-only.** Both
   config keys existed (`config/enrollment.php:53-54`) and were both
   `null` — no enforcement, no FR-ENR-004 workflow.
6. **The queue never reset.** `ticket_number` was `Q%06d` derived from the
   enrollment id, globally unique, climbing forever. `queue_date` existed
   as a column with no behavior. `QueueTicketStatus::Cancelled` was a dead
   enum case — no skip, no no-show path.
7. **206 of 211 seeded professor accounts were disconnected.**
   `CatalogFacultySeeder` seeds 206 real-named faculty from a CSV, but
   `SectionSeeder`/`DemoEnrollmentSeeder` both used `->first()`, so a
   single `faculty.seed@grc.test` account owned all 448 demo sections.

All seven were addressed together because they share one theme: PRD
sub-processes whose mechanism already existed in the schema or config but
whose actual value or connection was missing — the same category of gap
ADR 0021 closed for grading and queue timing.

## Decision

### Assessment & fees — computed once, at Registrar approval, never re-derived silently

`config/fees.php` follows the exact §17-provisional convention
`config/enrollment.php` already established: `tuition_per_unit` and
`currency` are env-overridable with a documented default; the
`miscellaneous` fee list is **file-only, not env-overridable** (same
reasoning as `enrollment.grading.special_marks` — a list can't cleanly
round-trip through a flat env var), with a docblock stating none of it is
GRC-approved.

`App\Domain\Billing\AssessmentComputation` is a pure static function —
no `config()`, no DB access inside `App\Domain\*`, mirroring
`PrerequisiteEvaluator`/`GradePointAverage`. It takes total units and a
resolved fee schedule and returns line items plus a total, using `bcmath`
half-up rounding (`bcadd(bcmul($units, $rate, 4), '0.005', 2)`) — never
float multiplication for money, and never plain truncation, which would
silently shortchange a fractional-unit computation (Leadership subjects
carry 1.5 units).

`App\Actions\Billing\AssessEnrollment::execute()` is called exactly once,
from inside `TransitionEnrollment`'s `registrar_approve` branch, in the
same transaction and the same row lock as the existing `QueueTicket`
creation. It is check-first idempotent (`Assessment::query()->where(
'enrollment_id', ...)->first()`) and is **never called again** — not on a
later `void`, not on an add/drop. The assessment is a snapshot of what was
approved, not a live-recomputed balance; a `void`'d enrollment keeps its
assessment row the same way it already keeps its orphaned `QueueTicket`.
Post-payment add/drop reassessment is an explicit, deliberate §17 gap, not
an oversight — there is no institutional policy yet for what happens to
an already-assessed total when a student's subject list changes after
payment.

The assessment folds into the **same** audit row and notification the
approval already writes — no second `AuditRecorder::record()` call. This
was a hard constraint, not a preference: `EnrollmentsEndpointTest` asserts
`AuditLog::query()->sole()` / `Notification::query()->sole()` for a single
approval, and a second write would break that invariant for every existing
caller, not just this feature.

`ConfirmPayment` now defaults an omitted `amount` to the assessment's
`total_amount`. An **explicitly supplied** amount is still trusted and
recorded as-is, mismatch or not — there is no partial-payment or
overpayment policy in the PRD to enforce against, so ADR 0022 does not
invent one. The UI can show a balance; the backend does not reject one.

### Guided Cashier flow — one screen, nav cut 4 → 2

`payment-queue` becomes a single guided component: a highlighted "Now
serving" card (student, units, amount due pre-filled from the assessment,
Confirm payment / Skip / Call next), a Waiting table ordered priority-first
then by ticket id, and a Served-today table. `serving-number`,
`payment-confirmation`, and `com-finalization` are deleted as separate nav
items — the workflow they represented (call → confirm → COM) is now the
single screen's own sequence, not three destinations a cashier has to
navigate between.

A new `payment-records` module (Accounting **and** Registrar Head) is
backed by a new, narrow `GET /api/v1/payments` endpoint with a
`confirmed_on` date filter. This is a deliberately new, narrow read against
`Payment` directly, rather than widening `Enrollment::scopeVisibleTo` to
keep showing enrollments after they leave `pending_payment` — a
payment-history list is Accounting's own record of what it already did,
not a wider grant into enrollment state it no longer needs to act on.
`Payment.confirmed_by` stays excluded from every Resource (actor-privacy
convention, same as `QueueTicket.served_by`).

### Queue overhaul — daily reset, priority, skip, single-active-serving

`ticket_number` moves from a global enrollment-id-derived value to a
per-day sequence (`Q001`, `Q002`, ...), gated by replacing the old global
`unique(ticket_number)` constraint with a composite
`unique(queue_date, ticket_number)` — the reset was impossible until that
constraint changed shape, so the migration had to precede the numbering
logic, not follow it.

A new `priority` column (`regular|priority`) is cashier-markable via a new
`mark_priority` transition, itself an audited action. **This project does
not invent a priority-eligibility policy** (e.g., PWD/senior/pregnant
criteria) — marking is a cashier judgment call, flagged §17-provisional
exactly like `viability_threshold`, not a confirmed institutional rule.
`skip` revives the previously-dead `QueueTicketStatus::Cancelled` case,
valid from either `Waiting` or `Serving`. Serving a new ticket
bulk-completes whatever ticket was already `serving` that `queue_date`
first (single-active-serving) — unaudited per-row, mirroring the existing
precedent `ConfirmPayment`'s bulk `EnrollmentSubject` status transition
already set. `served_by` records which cashier served a ticket but is
never exposed via `QueueTicketResource` (actor-privacy convention).

**Amendment:** `skip` no longer targets `QueueTicketStatus::Cancelled`. A
later change (2026-08-05, the Cashier queue live-refresh and skip-requeue
slice) redirected it to `waiting` instead, stamping a new `requeued_at`
column so the ticket lands at the back of its own priority tier rather
than disappearing. This means `Cancelled` is now unreachable in practice —
there is no remaining way for Accounting Staff to remove a ticket from
today's queue (e.g. a no-show). That gap was not evaluated by this ADR and
is not yet resolved; it is a known limitation, not an intentional
decision.

`QueueTicket::position()` is computed server-side, only for the caller's
own ticket: priority tickets ahead (or, if the caller is priority
themselves, only priority tickets ahead of them by id), then regular
tickets ahead. This deliberately never exposes the whole day's queue to a
student — only "how many people are ahead of you."

### Unit cap + overload approval (FR-ENR-004) — mechanism-implemented, value-flagged

`OverloadEvaluator` (pure static, same shape as `PrerequisiteEvaluator`)
evaluates total submitted units against the two existing config keys and
returns one of three verdicts: within `max_regular_units` → unaffected;
over it but within `overload_max_units` → permitted, but the enrollment is
flagged `requires_overload_approval` and Registrar Staff must explicitly
acknowledge (a checkbox in the approval dialog, enforced server-side via
`overload_acknowledged` on the transition request) before approving; over
`overload_max_units` entirely → hard `422` reject at submission, mirroring
the existing oversold-seat race-condition guard pattern.

Both config keys default to `null`, and with both `null` the evaluator
always returns "within regular" — **this changes no existing behavior
until GRC sets a real number.** This is the same
mechanism-implemented/value-flagged pattern already established for
`viability_threshold` and `stuck_threshold_days`: the code path exists and
is tested, but nothing acts on it until an institutional decision fills in
the value.

### Student process gaps — Withdraw button, amount due, queue position

`EnrollmentWithdrawPanel` is a new component that finally calls
`useCreateWithdrawalRequestMutation` — a hook that has existed, fully
wired to a working backend, since ADR 0021's slice, with zero callers. The
backend was never the gap; the UI was. It follows the same
required-reason, `AlertDialog`-confirmed pattern as the existing Add/Drop
panel it sits beside. `EnrollmentQueuePaymentPanel` renders the new
`assessment` breakdown and the `position()`-derived "N students are ahead
of you" / "You're next in line" message.

### 10 connected professors — a real 1:1, not a bigger placeholder pool

`DemoEnrollmentSeeder`'s demo blocks offer exactly 10 distinct subjects
across all four year levels (`CS201`, `MATH102`, `GE102`, `LEAD 2`,
`CS301`, `LEAD 4`, `CS303`, `LEAD 6`, `CS402`, `LEAD8`). Rather than
picking a professor from the existing 206-row CSV-imported catalog (none
of whom have any `FacultyAvailability` declared, and whose subject
coverage doesn't line up with this specific demo curriculum), the seeder
now creates 10 new `User` rows, one per subject, each owning **every**
block-section instance of their subject across every block code and year
level that offers it — a real department shape: one instructor, several
sections of the same course. Each also gets a declared weekday
availability window and a rank-1 `FacultySubjectPreference`, so logging in
as any of them exercises the real Teaching Schedule / Class Roster / Grade
Submission path, not just a `professor_id` foreign key with nothing behind
it.

This is deliberately scoped to `DemoEnrollmentSeeder`'s own
`BSIT-DEMO`-curriculum block sections — not every section platform-wide
that happens to share one of those 10 subject codes.
`ProgramChairScheduleSampleSeeder` independently generates its own
block-exclusive sections for the separate, real `BSIT` program's curriculum
that happens to reuse some Leadership subject codes (e.g. `LEAD8` also
backs a section coded `IT401`); those stay owned by that seeder's own
"Sample Faculty" fixture, which exists for a different testing purpose
(exercising the Program-Chair-schedule-authoring pipeline, not the student
demo
roster) and is intentionally left alone.

## The §17 boundary — what's provisional here, explicitly

Every value below is a placeholder the code enforces mechanically, not an
institutional decision GRC has approved:

- `fees.tuition_per_unit`, `fees.currency`, `fees.miscellaneous` — a
  reasonable-looking guess, not a real fee schedule.
- `enrollment.max_regular_units`/`overload_max_units` — still `null` by
  default; setting them is the actual GRC decision this mechanism is
  waiting on.
- Queue `priority` marking — a cashier judgment call with no encoded
  eligibility criteria (no PWD/senior/pregnant policy exists in this
  codebase).
- The post-payment add/drop reassessment gap (an assessed total is never
  recomputed after payment) — a deliberate scope boundary, not a bug,
  pending an institutional partial-payment/reassessment policy.

Nothing above should be read elsewhere in the codebase as confirmed
policy — the pattern is identical to `viability_threshold` and
`enrollment.withdrawal.releases_seats`, both already flagged the same way.

## Consequences

A student's enrollment now carries a real, itemized, GRC-defaults-backed
assessment the moment Registrar Staff approves it; the Cashier works from
one guided screen instead of navigating four; every confirmed payment
stays visible in a proper history instead of disappearing; a student can
withdraw through the UI for the first time; an over-cap submission is
either transparently flagged for Registrar Staff acknowledgement or
rejected outright, with zero behavior change while GRC's cap values stay
unset; the queue resets daily and supports skip/priority/single-active-
serving instead of climbing forever with no no-show path; and 10 of the
demo roster's professors are real, logged-in-and-exercisable identities
instead of a single shared placeholder.

**One real bug, found only by the new seeder test, not by the
implementation:** the first draft of the professor-ownership test queried
every `is_block_exclusive` section for a subject code platform-wide and
failed on `LEAD8`, which `ProgramChairScheduleSampleSeeder`'s unrelated
BSIT fixture also uses. Not a defect in the seeder itself — the test's
query was scoped too broadly. Fixed by scoping the assertion to
`BSIT-DEMO`'s own section plans.

**Known follow-ups, deliberately not absorbed into this slice:**
- The Playwright E2E suite's `SEED_STUDENT_SCENARIOS` fixture model
  predates both ADR 0021's 8-student/grade-history redesign and this
  slice's 10-professor seed — several specs still need rewriting against
  the current fixture shape.
- `docs/api/openapi.yaml` was updated for this slice's changes as they
  were made (`assessment`, `requires_overload_approval`, priority/position
  queue-ticket fields, the `/api/v1/payments` endpoint), but no test
  enforces the file stays in sync with the real routes going forward —
  the same known, undetected-drift caveat ADR 0021 already flagged.
- A full whole-repo regression pass (`php artisan test`,
  `vitest --no-file-parallelism`, `tsc --noEmit`, `eslint`,
  `phpstan analyse`, a fresh `migrate:fresh --seed`) and a live
  Playwright-MCP walkthrough are tracked as the final step of this slice's
  plan, not yet run as of this ADR being written — see `PROGRESS.md`'s
  2026-08-05 session entry for status.

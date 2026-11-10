# ADR 0017 — Dashboard Aggregation Layer: Aggregate-Only Endpoints, Enum-Driven Grouping, and the Factual/Judgment Split

**Status:** Accepted
**Date:** 2026-07-31

## Context

Phase 7c connects four of PRD §5.1's remaining leadership-visibility
modules: Dean's `enrollment-dashboard` and `stuck-students`, Executive
Director's `institution-dashboard`, and Registrar Head's `policy-settings`.
A full PRD audit found roughly a dozen bullet lines total covering all seven
modules originally deferred to this phase, and — more load-bearing than the
sparseness itself — PRD §17 never even registers "what counts as a stuck
student," "what a dashboard shows," or "which policy values are
configurable" as open institutional questions. They were never asked, not
merely unanswered.

Two structural facts shaped every decision below:

1. **No Action in this codebase had ever returned an aggregate before.**
   Every existing Action (`app/Actions/**`) returns either a paginated
   Eloquent collection or a single model. A dashboard needs counts grouped
   by status, not rows.
2. **Dean and Executive Director have zero existing read access to
   enrollment data.** `Enrollment::scopeVisibleTo` serves only Accounting
   Staff, Registrar Head, and a student's own record; both leadership roles
   fall through to the own-student branch and get nothing, and
   `EnrollmentPolicy::viewAny()` rejects them before the scope even runs.

## Decisions

**Aggregate-only endpoints for Dean and Executive Director; a narrower
minimal-field endpoint for `stuck-students`.** The tempting fix for fact 2
above — widen `Enrollment::scopeVisibleTo`/`EnrollmentPolicy` to include
both roles — would hand both of them row-level access to every student's
enrollment record. PRD §3.6 explicitly constrains this ("cannot alter
detailed student academic records unless separately authorized") and §9.4
guards it structurally. Instead, `enrollment-dashboard` and
`institution-dashboard` are backed by new `DB::table(...)` aggregation
Actions that return counts and never touch `Enrollment::scopeVisibleTo` at
all — no student identity crosses the boundary. `stuck-students` is the one
PRD-authorized exception (§3.5 names the Dean as the authorized viewer of
stuck-student reports), so it gets its own narrower endpoint returning
`student_number`, status, and dwell days only — never name or email. Both
shapes follow `EligibleSubjectPolicy`'s documented precedent, already used
elsewhere in this API for "an own computed view, not a stored resource":
new `DashboardPolicy` (three abilities: `viewEnrollmentSummary`,
`viewInstitutionSummary`, `viewPolicySettings`) and a standalone
`StuckEnrollmentPolicy`.

**A third Action return shape: typed readonly value objects, not a
paginator or a model.** `App\Domain\Dashboard\{EnrollmentSummary,
InstitutionSummary,PolicySettingsSummary,StuckEnrollmentRow}` are plain
readonly PHP classes built directly from aggregation query results.
Choosing `DB::table(...)`/`selectRaw` over Eloquent model hydration was
deliberate: these queries do pure counting with dynamically-selected
columns, and building a fake "aggregate model" would have been more
indirection for no benefit, plus friction against Larastan's static typing
of Eloquent attributes. This is a genuinely new pattern for this codebase's
Action layer, not a variation on an existing one, and is recorded here for
that reason.

**Grouping is driven exclusively by `Enum::cases()`, never string
literals — and specifically by the two PRD-authoritative enums.** Most of
the enums a dashboard would naturally want to group by
(`SectionStatus`, `AcademicTermStatus`, `ProgramStatus`, `WithdrawalStatus`,
`TransfereeCreditStatus`, `QueueTicketStatus`, `AdmissionStatus`,
`AcademicStanding`) carry their own docblock warning: "PROVISIONAL
VOCABULARY — NOT AN APPROVED INSTITUTIONAL POLICY VALUE — replace via data
migration before production." Keying dashboard UI off string literals drawn
from any of those would silently break the moment GRC confirms real values.
`EnrollmentStatus` and `GradeStatus` are the two exceptions actually used
for grouping — both are PRD-authoritative today, not provisional.

**Never sum `payments.amount`.** Carried directly from the audit that scoped
this phase: `payments.amount` is nullable by §17 design (no required-field
or currency/rounding policy exists yet — see the open-decisions table). No
dashboard Action sums or averages it. `enrollment-dashboard`'s "payment
confirmed" funnel stage counts payment *events* (rows in `payments`, unique
per enrollment), never money.

**`stuck-students` separates its factual half from its judgment half, and
ships with the judgment half unset.** Every non-terminal enrollment's dwell
time in its current status is always shown — computed from
`submitted_at`/`registrar_decided_at`/`payment_confirmed_at`, arithmetic on
existing timestamps, not policy. New config key
`dashboard.stuck_threshold_days` (default `null`) is the *only* judgment
call: when unset, the page states plainly that no institutional threshold
is configured and nothing is flagged; when GRC sets a value, rows past it
are flagged. This is the same mechanism-implemented/value-flagged shape as
`enrollment.max_regular_units`.

Candidacy for "in progress" is scoped to
`Draft`/`PendingRegistrarApproval`/`PendingPayment` specifically, not
`Enrollment::scopeActive()` (which also includes `Enrolled`). This was
found and fixed via live-data inspection against the dev database, not a
failing test: an already-`Enrolled` student showed up as a "stuck"
candidate under the broader scope, which is semantically wrong — they have
completed the enrollment process, not stalled in it. The narrower scope is
derived directly from the PRD-authoritative lifecycle order
(`draft → pending_registrar_approval → pending_payment → enrolled`), not a
new institutional definition — the dwell-time *threshold* is still the only
value genuinely §17-open.

**`policy-settings` is read-only, backed by a hardcoded list of 11
`PolicyValueState` entries.** Six reflect a real `config('enrollment.*')`
key (`grading.comparison`, `grading.passing_grade`, `max_regular_units`,
`overload_max_units`, `withdrawal.releases_seats`,
`dashboard.stuck_threshold_days`) and report whether it is configured,
provisional, or unset. Five have no config key at all
(`sections.viability_threshold`, `queue_tickets.policy`,
`payments.required_fields`, `academic_grades.honors_rule`,
`compliance_reports.fields`) and report a `no_mechanism` state with a
`prd_reference` pointing at the specific open §17 question. This list is
hand-maintained, not derived by introspecting `config/enrollment.php` at
runtime — the values worth surfacing (and their PRD references) are
editorial judgment, not something a generic config walker could produce.
Making the module *writable* is explicitly out of scope: it would require
deciding which values are Registrar-editable at runtime, an unmade
decision, and today configuration is env-var-only with no settings table.

## Consequences

- If GRC confirms an actual "stuck" threshold, only
  `DASHBOARD_STUCK_THRESHOLD_DAYS` (or `config/enrollment.php`'s default)
  needs to change — no code path changes shape, matching every other
  mechanism-implemented/value-flagged decision already in this codebase.
- `compliance-reports` and the shared `reports` id (Dean and Executive
  Director) remain placeholders. Both are qualitatively different from the
  four modules this ADR covers: they need an actual field list, export
  format, and sign-off authority, none of which is arithmetic over an
  existing enum — the aggregate-only pattern above does not apply to them.
- A future settings-table design (to make `policy-settings` writable) would
  need its own ADR — this one deliberately does not sketch that schema, to
  avoid asserting a design for a decision (which values are editable, by
  whom) that has not been made.
- `DashboardPolicy`/`StuckEnrollmentPolicy` are the only places a future
  role change to dashboard visibility needs to touch — the Actions and
  Resources are role-agnostic, matching ADR 0011's identical observation
  about `ScheduleProposalPolicy`.

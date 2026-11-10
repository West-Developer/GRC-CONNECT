# ADR 0011 — Approval Workflow: Per-Action Authorization and the Publish–Section Linkage

**Status:** Accepted
**Date:** 2026-07-28

## Context

PRD §5.1 FR-SCH-007 through FR-SCH-009 require a schedule approval workflow:
the five-state lifecycle `draft → dean_approved → executive_approved →
published → closed` (PRD §4.1), a required reason when a proposal returns to
`draft`, and publication that exposes the term's schedule to students and
professors. The `schedule_proposals` table (found already written in the
schema-foundation task) has no foreign key to `sections` at all — the two
tables were designed independently, each with its own status enum. This
slice has to decide how a single `PATCH` request drives six different
transitions needing six different approving roles, and how "publish" — a
proposal-level state change — actually makes sections visible when no
relationship links the two tables.

## Decisions

**One ability per transition family, not one `role:` middleware.** Every
other write-gated resource in this API (`curricula`, `sections`,
`faculty-availabilities`, `faculty-subject-preferences`) is single-role at
the route level. `schedule_proposals` cannot follow that pattern: `dean_approve`/
`dean_return` need the Dean role, `executive_approve`/`executive_return`/
`publish` need the Executive Director role, and `close` needs the Registrar
Head role, all through the same `PATCH /schedule-proposals/{scheduleProposal}`
route. `role:` middleware operates before the request body is even read, so
it cannot key off an `action` field. Instead `ScheduleProposalPolicy` exposes
four abilities (`approveAsDean`, `approveAsExecutive`, `publish`, `close`) and
`ScheduleProposalController::update()` maps the six actions onto them (the
two `*_return` actions share their forward-approval counterpart's ability,
since returning is the same role's own reconsideration of their own
checkpoint — see the role-mapping rationale below). `POST /schedule-proposals`
(submission) *is* single-role (`program_chair`) and keeps the `role:`
middleware, consistent with every other create endpoint.

**Role-per-transition mapping** (flagged as an assumption in the approved
plan, not confirmed literal PRD text):

| Action | Requires current status | Actor |
|---|---|---|
| `dean_approve` | `draft` | Dean |
| `dean_return` | `dean_approved` | Dean |
| `executive_approve` | `dean_approved` | Executive Director |
| `executive_return` | `executive_approved` | Executive Director |
| `publish` | `executive_approved` | Executive Director |
| `close` | `published` | Registrar Head |

Each `*_return` action is the *same* role's own checkpoint reconsidered —
the Dean recalling their own pending approval before the Executive Director
acts on it, and the Executive Director recalling theirs before publish —
rather than a later role rejecting an earlier one's decision. This keeps
each role's actions symmetric (approve forward or recall backward, at
exactly one checkpoint) and matches the literal action names given in the
plan. `decision_reason` is required by validation exactly when the action is
one of the two `*_return` actions — the PRD's "required reason" rule applies
to returning to draft specifically, not to every transition.

**Both the current-status precondition and the reason requirement are
validated in `UpdateScheduleProposalRequest`, not the Action class.**
`App\Actions\Scheduling\TransitionScheduleProposal` performs the mutation
only — by the time it runs, the request has already confirmed the action is
legal given the proposal's current status and that any required reason is
present. This mirrors the curriculum catalog's split (ADR 0009): validation
owns "is this legal," the Action owns "make it so."

**No new foreign key between `schedule_proposals` and `sections`.**
`publish` bulk-updates every `Section` in the proposal's `academic_term_id`
from `planned` to `published`, in the same `DB::transaction()` as the
proposal's own status change — a term-scoped bulk update standing in for a
relationship neither table was designed with. This was the deliberately
minimal option: adding a column or join table now would be a schema change
to two already-shipped tables for a linkage the found design never
anticipated, whereas a bulk `UPDATE ... WHERE academic_term_id = ? AND
status = 'planned'` needs no migration and is exactly as correct given a
term has at most one non-closed proposal at a time (see below).

**One active proposal per term, enforced in the request, not the schema.**
`StoreScheduleProposalRequest` rejects creating a new proposal for a term
that already has one in any non-`closed` status. This is the same reasoning
as `enrollments.active_academic_term_id` (a generated column) and
`sections`' unique code constraint: the rule is "at most one *live* thing at
a time," which a plain `UNIQUE(academic_term_id)` would get wrong (it would
permanently block resubmission after a term's schedule closes). Unlike
`enrollments`, this slice does not add a generated column for it — the check
only needs to run at creation time, not be continuously enforced by the
database, so an application-level guard is sufficient and avoids a schema
change to an already-shipped table.

## A Laravel testing note, not a design decision

While writing the endpoint tests, chaining multiple *different* authenticated
users' tokens within a single test method (Program Chair submits, then Dean
approves, then Executive Director approves and publishes, then Registrar
Head closes) surfaced a Sanctum guard-caching quirk: the guard resolves and
caches a user once per guard instance, and that cache outlives a single
simulated request within one test method, so a later `withToken()` swap to a
different user was not taking effect. `$this->app['auth']->forgetGuards()`
did not resolve it either. Rather than fight framework internals further,
the lifecycle is tested as four separate single-actor tests (each
precreating the proposal directly at whatever status the transition under
test requires) — the same structure every other endpoint test in this API
already uses, and no less thorough for it.

## Consequences

- If GRC later confirms a *different* role should own `publish` or `close`,
  only `ScheduleProposalPolicy` and the mapping table above change — the
  Action class and request validation are role-agnostic.
- A future requirement to track *which* sections belong to *which* proposal
  explicitly (rather than inferring it from `academic_term_id`) would need an
  actual schema change (a `schedule_proposal_id` column on `sections` or
  similar) — this slice's bulk-update approach only works because a term has
  at most one live proposal at a time.
- Returning a proposal from `executive_approved` all the way back past
  `dean_approved` to `draft` in one step (skipping re-review) is intentional,
  not an oversight — the PRD states a returned proposal moves back to draft,
  not to the immediately prior state.

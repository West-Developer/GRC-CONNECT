# ADR 0019 — Schedule-transition notifications, derived returned state, and Registrar-controlled enrollment opening

**Status:** Accepted
**Date:** 2026-08-03

## Context

Manual UI testing surfaced three related gaps in the schedule-approval and
enrollment-startup flow:

1. Only `publish` ever wrote a notification. A Program Chair submitting, a
   Dean approving, or a reviewer returning a schedule produced no signal to
   the people who needed to act next or who needed to know what happened.
2. A returned schedule proposal is not a distinct database state —
   `dean_return`/`executive_return` both resolve `status` back to `draft`
   (see ADR 0011 / `ScheduleProposalTransitionRules`) — so every UI screen
   that showed status rendered a returned proposal identically to a never-
   submitted draft, using the same neutral grey badge as every other state.
3. Nothing ever transitioned an academic term into `semester_ongoing` except
   a seeder, and the stored `enrollment_opens_at`/`enrollment_closes_at`
   dates were written and displayed but never compared against `now()`
   anywhere — `POST /enrollments` accepted a `draft` or `archived` term id.

Two constraints shaped every option considered:

- **No queue, scheduler, event/listener system, or mail transport exists**
  (`QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`, no `app/Jobs`, `app/Events`,
  or `app/Listeners`). A design that assumed a background worker or a
  timed job (e.g. "notify everyone the instant a window opens") was not
  buildable without first adding that infrastructure, which was out of
  scope for this slice.
- **ADR 0018 already committed to "no date alone silently changes lifecycle
  status."** Any enrollment-opening design that let a stored date alone
  flip a term live would contradict that decision.

## Decision

**Notifications.** Add a pure, DB-free `App\Domain\Scheduling\ScheduleTransitionNotificationPlan`
describing, per transition, which audience (`submitter` or a role — Dean and
Executive Director are not college-scoped, so "role" means every active user
with that role, not a single reviewer) receives which message. A thin
`App\Actions\Scheduling\NotifyScheduleTransition` resolves roles to user IDs
and writes rows via a new batched `NotificationRecorder`, called from
`SaveSectionPlan::submit()` (guarded so a college submitting across several
curricula only notifies the Dean once) and from `TransitionScheduleProposal`
for every non-publish transition. `publish`'s existing inline notification
logic is untouched. Four new `NotificationType` cases were added:
`schedule_submitted_for_dean`, `schedule_dean_approved`,
`schedule_executive_approved`, `schedule_returned`.

**Returned state stays derived, not stored.** No new status and no
migration. `ScheduleProposalResource` gained two computed fields —
`is_returned` (the same `status === draft && !is_submitted && decision_reason
!== null` predicate every screen was already re-deriving, moved to one
authoritative place) and `returned_by_role` (read off the last `*_RETURNED`
audit entry) — so every consumer reads one flag instead of re-implementing
the derivation. A shared frontend `scheduleProposalPresentation()` helper
gives every status, including the derived returned state, a distinct
badge color (new `success`/`warning` badge variants) instead of the
previous uniform neutral grey.

**Enrollment opening stays status-driven, per ADR 0018.** A new
`open_enrollment` action on `TransitionAcademicTerm` moves the term to
`semester_ongoing` from `draft` or `for_dean_approval`, gated on at least
one published `schedule_proposal` existing for the term, and is the only
thing that makes the stored enrollment dates start mattering. Inside an
open term, a new `academic_term_year_level_windows` table (one row per
year level 1–4, seeded from the term-wide dates at term creation) lets the
Registrar Head stagger which year level may enroll and when, defaulting to
the term-wide window when a year level has no override. A pure
`App\Domain\Enrollment\EnrollmentWindowResolver` — status first, then the
year-level window, matching ADR 0018's ordering — is the single gate wired
into `StoreEnrollmentRequest` as the first check, ahead of every existing
per-section validation rule.

**No date-driven notification.** Because nothing runs at a scheduled
instant, "your year level just opened" cannot be pushed the moment it
becomes true. Availability is instead computed live on every read
(`BuildEnrollmentScheduleSummary`) and shown as a banner; the notification
bell's one deliberate exception to "this app does not poll" is a 60-second
refetch of the unread-count query only, not a mechanism for detecting
window transitions.

## Consequences

Every schedule-proposal transition now has a notification recipient rule
that can be unit-tested without a database
(`ScheduleTransitionNotificationPlanTest`), independent of whether the
local MariaDB instance can run `RefreshDatabase`-backed feature tests.
`EnrollmentWindowResolver` is similarly pure and fully covered
(`EnrollmentWindowResolverTest`). The Dean and Executive Director remain
not college-scoped for notification purposes, matching their existing
(not college-scoped) read access — a Dean is notified about every college's
submission, not just one. A future background-job system could add a
date-triggered "enrollment opened" push without changing
`EnrollmentWindowResolver` or the schedule it reads — only the delivery
mechanism would need to be added.

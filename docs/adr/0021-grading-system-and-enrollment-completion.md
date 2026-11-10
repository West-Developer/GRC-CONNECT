# ADR 0021 — Grading system, auto-derived standing, and enrollment-cycle completion

**Status:** Accepted
**Date:** 2026-08-04

## Context

Three gaps blocked the enrollment cycle from ever completing end-to-end:

1. **Half a grading system.** `academic_grades` existed with a free-text
   `DECIMAL final_grade` — no remarks mapping, no Complete/Not-Complete for
   Leadership subjects, and the `lock` transition existed in the API but
   **no component ever called it**, so no grade had ever reached `locked`.
   There was no prospectus, no grade slip, and no print capability anywhere
   in the repo (zero PDF library, zero `@media print`).
2. **Queue number issued at the wrong time.** `SubmitEnrollment` created the
   `QueueTicket` at submission, before any registrar review, and
   RegistrarHead — not RegistrarStaff — was the configured approver.
3. **No add/drop/change-section workflow.** `academic_terms.add_drop_deadline_at`
   was a column with zero behavior attached to it.

Since grades feed both prerequisite evaluation and Regular/Irregular
classification, the grading half had to come first.

## Decision

**`GradeMark` is a backed enum, not a raw decimal.** `App\Domain\Academic\GradeMark`
covers `1.00`–`5.00`, `C`, `NC`, `INC`, `DRP`, with `isPassing()`,
`isCompletion()`, `countsTowardGpa()`, and `blocksRegularStanding()`. A new
`mark` column on `academic_grades` stores it; `final_grade` remains as a
derived numeric mirror (for `BuildEligibleSubjectPool` and existing reports)
but stops accepting direct writes. `CompletionOnlySubjectRule` matches every
real Leadership spelling (`LEAD 1`, `LEAD2`, `LEAD 3`, `LEAD4`, ...) via a
normalize-then-regex `^LEAD\d*$`, not `str_starts_with`.

**The LEAD prerequisite chain is short-circuited before the evaluator, not
inside it.** `LEAD2` requires `LEAD1`, and `LEAD1`'s only possible grade is
`C` — a non-numeric mark. `BuildEligibleSubjectPool` treats a `C` completion
mark as satisfying prerequisite the same way a passing numeric grade does,
before `PrerequisiteEvaluator` ever runs, so the eight-subject Leadership
chain can never be blocked by a numeric-comparison edge case.
`PrerequisiteEvaluator` itself is untouched.

**The Regular/Irregular classifier is pure and re-derives on every lock.**
`ReclassifyStudentEnrollmentCategory` runs inside the same transaction as a
`lock` transition, writes `enrollment_category` + `enrollment_category_derived_at`
on `student_profiles`, and always audits + notifies the student — because
`enrollment_category` feeds `EnrollmentAudience::forStudent()`, so locking a
`5.00` can silently move a student's own enrollment window. (The classifier
itself was `App\Domain\Enrollment\EnrollmentCategoryClassifier` at the time
of this ADR; it was later replaced by the term-scoped
`App\Actions\Academic\ClassifyEnrollmentStanding` — see
`docs/superpowers/specs/2026-08-19-term-scoped-enrollment-standing-design.md`
— without changing the shape of this decision.)

**Print is a stylesheet, not a library.** `body[data-printing]` + CSS
`visibility` (not `:has()`, so Vitest can assert `document.body.dataset.printing`
directly) isolates one document for `window.print()`. `<GradeSlipDocument>`
and `<ProspectusDocument>` are parameterized by student ID so the same
components serve both the student's own view and the Registrar's
look-up-any-student view — avoiding the obvious duplication trap.

**Queue-after-approval moves the write, not just the trigger.**
`QueueTicket::create` moved from `SubmitEnrollment` to `TransitionEnrollment`'s
`registrar_approve` branch. `EnrollmentPolicy::decideApproval` now targets
RegistrarStaff (RegistrarHead keeps `void`); RegistrarStaff was added to
`viewAny` and `Enrollment::scopeVisibleTo`. The student-facing submission
notification no longer mentions a ticket number.

**Add/drop is a dedicated table and a pure window resolver.**
`enrollment_change_requests` (`type: add|drop|change_section`, required
`reason`, `status`, decision fields) is decided by RegistrarHead, visible to
RegistrarStaff — matching the existing enrollment-approval split rather than
inventing a new one. `AddDropWindowResolver` opens the window only once
`semester_ongoing` **and** past enrollment close **and** before
`add_drop_deadline_at` — the first real behavior attached to that
long-dormant column.

## What Phase 10 verification found and fixed

A live Playwright-MCP walkthrough (not just the automated suites) surfaced
three bugs the automated tests didn't catch, because none of them exercised
the real cross-action sequence a live user does:

1. **`EnrollmentSubject.status` never left `Selected`.** `ConfirmPayment`
   transitioned the parent `Enrollment` to `Enrolled` but never touched its
   `EnrollmentSubject` rows. `EnrollmentSubjectStatus::Enrolled` was defined
   in the enum but referenced nowhere else in the codebase.
   `grade-submission-workspace.tsx`'s roster filters strictly on
   `status === "enrolled"`, so **no professor could ever see a student to
   grade**, for any student, system-wide — the single most central path in
   this whole slice. Fixed by having `ConfirmPayment` bulk-transition
   `Selected → Enrolled` on the enrollment's subjects in the same
   transaction as the payment confirmation, alongside the existing test
   fixtures in `ClassRostersEndpointTest` that had (correctly) assumed this
   transition existed all along.
2. **A newer table missed its database grant.** `enrollment_change_requests`
   (added in this slice) never received `grc_app`'s deferred
   `GRANT SELECT, INSERT, UPDATE, DELETE` — every add/drop list call 500'd
   with `SELECT command denied`. This is the same class of gap
   `docs/runbooks/mariadb-local.md` already documents for the original four
   tables; the runbook now calls out explicitly that every new table needs
   its own grant, with this table as the caught example.
3. **A rejected add/drop request showed a misleading generic error.**
   `student-add-drop-workspace.tsx` swallowed the backend's specific 422
   message (e.g. "The add/drop window opens once enrollment closes for this
   term.") behind a generic "check your connection" fallback in both its
   drop/change and add mutations. The backend's business-rule enforcement
   was correct throughout; only the frontend's error surface was misleading.
   Fixed by extracting the first `fieldErrors` message via the existing
   `isApiClientError` helper, with the generic string as a true fallback.

None of these three were regressions from this slice's own design — the
first two are gaps inherited from earlier slices that this slice's live
walkthrough was the first to actually exercise; the third is local to a
component this slice added. All three are now covered by regression tests
(`PaymentConfirmationEndpointTest`, the runbook update, and
`student-add-drop-workspace.test.tsx`).

## Consequences

The cycle now runs end-to-end and was verified live, not just by assertion:
professor encodes a numeric mark and a Leadership C/NC mark → Registrar Head
locks (mandatory confirmation dialog, permanent, no unlock) → student's
prospectus and grade slip render in the reference paper form and print
correctly → Regular/Irregular status is the classifier's own verdict, shown
with its reason → student submits a block enrollment and sees "waiting for
approval" with no queue number → Registrar Staff approves → queue number
`Q000001` appears at that exact moment and the Cashier is notified → payment
confirmation issues the Digital COM and only then does the student appear on
their professor's roster → outside the enrollment window, a student's
drop/add/change-section request (with required reason) reaches Registrar
Head for decision and Registrar Staff for visibility.

A full serial Vitest run (`--no-file-parallelism` — the default parallel run
is unreliable under concurrent load, producing dozens of false-positive
timeouts) also caught two smaller gaps: `portal-module-page.test.tsx`'s
module-heading fixture map had been updated for this slice's new modules in
one place (`module-registry.test.tsx`) but not the other; and
`grade-slip-document.test.tsx` asserted on a value (`"1.50"`) that
coincidentally matched both a row's mark and the slip's overall GPA. Both
fixed; a further 3 pre-existing timeout failures in unrelated files
(`admission-provisioning-workspace.test.tsx`,
`curriculum-workspace.test.tsx` — last touched by the separate, pre-existing
enrollment-startup WIP this repo's working tree already carried) reproduce
in isolation and are unrelated to this slice, so were left alone.

**Known follow-ups, deliberately not absorbed into this slice:**
- The Playwright E2E suite's fixture model (`SEED_STUDENT_SCENARIOS`,
  5-student/lifecycle-stage assumptions) predates this slice's 8-student/
  grade-history seed redesign; several specs need rewriting against the new
  fixture shape. Not attempted here — flagged as its own follow-up slice.
- `docs/api/openapi.yaml` was not updated for the ~10 endpoints this slice
  added (prospectus, grade slip, grade approvals, add/drop requests, etc.).
  The file has no test enforcing sync with the real routes, so this is a
  known, undetected drift rather than a silent regression.
- Lock is permanent by design (PRD-aligned) — there is still no unlock,
  reject, or return transition in the grade state machine. If that's ever
  needed, it is a deliberate new decision, not an oversight.

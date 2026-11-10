# ADR 0030 — Registrar Approval Returns for Every Enrollment; Program Head Stage for Irregular

**Status:** Accepted
**Date:** 2026-09-26
**Amends:** PRD §4.2 (enrollment lifecycle), PRD §3.4 / §3.7 / §3.8 (who approves), and reverses the 2026-09-16 rule (stakeholder Docs 1–4, "Regular students: remove the Registrar approved step") that auto-approved regular submissions straight to `pending_payment`. ADR 0022 (assessment created at approval) and ADR 0023 (queue ticket claimed at the kiosk) are unchanged.

## Context

Stakeholder Doc 14 asks for Registrar approval to come back: a student who enrolls in a section needs approval from Registrar Staff or the Registrar Head, and a student who picked the wrong section can cancel until then (a later slice). For irregular or overload students the Program Head still checks the schedule first, then the enrollment goes straight to the Registrar. The owner confirmed this in chat on 2026-09-26.

Before this change (verified in code): `SubmitEnrollment` sent regular block submissions directly to `pending_payment` with an immediate assessment; only irregular or overload submissions waited at `pending_registrar_approval`, where the Program Chair, not the Registrar, decided them (`registrar_approve`), and the Registrar page (`enrollment-approvals`) was in nobody's navigation.

## Decisions

1. **Lifecycle.** `draft → pending_registrar_approval → pending_payment → enrolled` for a regular submission; `draft → pending_program_head_approval → pending_registrar_approval → pending_payment → enrolled` for an irregular or overload one. `rejected` can end either approval stage. New `EnrollmentStatus::PendingProgramHeadApproval` (`enrollments.status` is a plain string, so no schema change for the value) and `enrollments.program_head_decided_at`.
2. **Who decides.** Program Head: `program_head_approve` / `program_head_reject`, own college only (record-level, `EnrollmentPolicy::decideProgramHeadApproval`). Registrar Staff and Registrar Head: `registrar_approve` / `registrar_reject` for every enrollment (`decideApproval`); the Program Chair can no longer approve at the Registrar stage. Rejection needs a reason in both stages.
3. **No auto-approval.** `SubmitEnrollment` creates no assessment and no queue ticket for anyone. The assessment is created inside `registrar_approve`, exactly as it already was for irregular students, so nothing reaches `pending_payment` without one.
4. **Overload acknowledgement** moves to where the overload is judged: `program_head_approve` requires it; `registrar_approve` requires it only for an enrollment the Program Head never saw (`program_head_decided_at IS NULL`).
5. **In-flight data.** The migration moves enrollments that were waiting for a decision and are irregular or overload-flagged back to `pending_program_head_approval` (that was the Program Chair's queue); regular ones simply join the Registrar's queue. `down()` restores the single earlier stage.
6. **Registrar Head student information.** A narrow read (`GET /students/{studentProfile}/registrar-profile`, Registrar Head only) feeds the student-name modal in the approvals queue. It does not open the general student-profile directory, which stays with Admission Staff.
7. **Navigation.** The `enrollment-approvals` module is listed for Registrar Head and Registrar Staff (it was orphaned). An irregular enrollment still with the Program Head is shown to them view-only under "Awaiting Program Head".

## Consequences

- Students see "Registrar approved" again in the enrollment timeline; irregular students see "Program Head approved" then "Registrar approved". The student queue view has a new stage `pending_program_head_approval`.
- Enrollment status dashboards, the Program Head analytics and the (soon removed) stuck-enrollment list include the new status among "in progress".
- The IT-control automation step `registrar_approve_all` only approves `pending_registrar_approval`; it does not run the Program Head step first, so an automated irregular cohort now stops at the Program Head stage (its tests were already failing before this change).
- Follow-ups in the same Doc 14 work: student cancel and Registrar void (S06), the Withdraw/Drop/Add/Change Section hub (S07), and the prerequisite waiver (S08).

## Update 2026-10-03 — Void removed from the approvals page; Reject on an approved enrollment

Stakeholder feedback: the Enrollment Approvals page should not offer **Void**; an approved enrollment that is still unpaid should be **rejected** instead. The Registrar's page now offers only Approve and Reject on `pending_registrar_approval` rows, only Reject on `pending_payment` (Approved) rows, and nothing on rows still with the Program Head or the student (view-only). To make the second case real, `registrar_reject` is now also allowed from `pending_payment` (`TransitionEnrollment` and `UpdateEnrollmentRequest`); it ends in `rejected`, releases the seats, cancels a still-waiting queue ticket (as a void does) and notifies the student with the reason. The `void` action itself is unchanged in the API (the stale-enrollment sweep `AutoVoidStaleEnrollmentsCommand` still uses it); only the page's button is gone. The page polls every 5 seconds (ADR 0029's 15 seconds stays the default elsewhere).

## Update 2026-10-05 — a transferee's order is Program Chair, then the payment hold, then the Registrar

For a Transferee (who pays first, ADR 0043, amended 2026-10-05) the Program Chair stage keeps its place and the Registrar stage moves after the first payment: an Irregular or overload submission waits at `pending_program_head_approval`; the Chair's approval (or the student accepting the Chair's changes, ADR 0040) sends it to `awaiting_first_payment`, not to `pending_registrar_approval`; the Cashier's confirmed first payment then releases it to the Registrar. A regular block submission goes straight to the hold. `EnrollmentRouting::statusAfterReview` is the single rule. Every other student is unchanged.

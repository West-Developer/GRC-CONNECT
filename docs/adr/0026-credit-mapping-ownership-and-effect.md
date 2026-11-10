# ADR 0026 — Credit Mapping: the Program Chair Maps, the Registrar Approves, and an Approved Credit Counts

**Status:** Accepted
**Date:** 2026-09-24
**Supersedes:**
- PRD §3.8's "Registrar Staff process transferee credit mappings" (PRD §3.1, §3.4, §3.8 and FR-FIN-003 are amended to match).
- The Registrar-Staff-only reading documented in `TransfereeCreditPolicy` and `docs/data-dictionary/enrollment-records.md`.
- The earlier decision that an approved `TransfereeCredit` is record-only and must never feed `BuildEligibleSubjectPool` (PROGRESS.md Phase 7b notes). **PRD §17's open item — the official cross-institution grade equivalence — is not resolved by this ADR** (see decision 4).

**Amended 2026-10-15 (ADR 0050):** an `endorsed` credit (passed by the Program Chair, awaiting the Registrar Head) counts for the student's enrolment provisionally and no longer blocks enrolment; only a `pending` credit does. Promotion and the Chair's suggestions still read approved credits only.

## Context

Stakeholder feedback (Google Doc "WESTLIE", 2026-09-24) said credit mapping belongs to
the **Program Chair**, with Registrar Staff only **approving** it. A student who is a
transferee (or a returnee on an old curriculum) is handled by the Program Chair. The
Student should get a button to **request** credit mapping (previous school, subject
title, grade, units, school year, semester), the request should **appear to the Program
Chair**, and the system should **automatically suggest** which subjects can be credited,
while manual crediting stays available.

Before this, Registrar Staff recorded and approved credits; a Student could not ask for
one (the uncommitted student dialog's `POST` was refused with 403); nothing told a
Program Chair anything; the only "suggestion" was a code-or-title match written straight
into `subject_id`; and an approved credit was read by nothing.

## Decisions

**1. The route.** Student request → Program Chair maps → Program Chair **endorses** →
Registrar Staff approve or reject. New status `endorsed`; the chain is
`pending` → `endorsed` → `approved` | `rejected`, and the Program Chair may also
`decline` a `pending` request (a reason is required and the Student is told). Only
*approval* is the Registrar's.

| Who | May |
|---|---|
| Student | Ask for a credit for themselves (the API pins the student to the caller and ignores any `student_id`; `subject_id` is prohibited; school year and semester are required); read their own credits. |
| Program Chair | Record a credit for a student **in their own college**; map, edit, endorse or decline a `pending` credit of theirs; ask for suggestions. |
| Registrar Staff | Approve or reject an **`endorsed`** credit; read every credit. Cannot create, edit or endorse. |
| Registrar Head | Read every credit (read-only, as for enrollments, grades and withdrawals). |

A Program Chair without an assigned college is unscoped, the same fallback
`TransfereeCredit::scopeVisibleTo` already used. The Program Chair's student lookup
(`GET /academic-record/students`) is now college-scoped too.

**2. Suggestions are advice, computed on demand.** `GET /api/v1/transferee-credits/{id}/suggestions`
(`SuggestCreditSubjects`) returns up to five subjects of **the student's own curriculum**,
best first, scored on subject code (spaces and punctuation ignored), title (word by word,
prefixes of four letters or more match, filler words ignored, Roman numerals I to V read as
digits) and units, each with the reasons. Subjects the student already passed, is credited
with, or has mapped through another open or approved credit are left out. Nothing is stored
and the API **never** writes a guess into `subject_id`: a mapping is only ever the Program
Chair's decision, so a guess can never be mistaken for one. The scoring encodes no
institutional equivalence rule.

**3. Requests and notifications.** A Student's request notifies the Program Chairs of the
student's college (`transferee_credit_requested`); an endorsement notifies Registrar Staff
(`transferee_credit_endorsed`); approval, rejection and a decline notify the Student
(`transferee_credit_approved` / `_rejected`). Repeating a still-open request returns the
existing row (HTTP 200) instead of a duplicate. Every step re-checks the credit's status
under a row lock, so a repeated or racing request changes nothing a second time. Audit rows:
`transferee_credit.created`, `.updated`, `.endorsed` (new), `.approved`, `.rejected` (a
decline is a rejection, with its reason).

**4. An approved credit counts as credited, without a GRC grade.** This reverses the
record-only decision. `ResolveCreditedSubjectIds` merges the two sources of a credited
subject — curriculum-migration credits and **approved transferee credits that are mapped
to a subject** — and replaces the inline queries in `BuildEligibleSubjectPool` (the subject
is excluded as completed, with a "credited from the student's previous school" reason, and
satisfies prerequisites), `ClassifyEnrollmentStanding` and `PromoteEligibleStudents`; the
prospectus lists them (`transferee_credits`). A `pending`, `endorsed` or `rejected` credit,
or one with no mapped subject, credits nothing. A credited subject carries no grade, exactly
like a curriculum-migration credit, so **no cross-institution grade is converted or
compared, nothing feeds a GWA, and PRD §17 (official grade equivalence) stays open.**

**5. Returnees from an old curriculum keep their existing route.** The Curriculum Editor's
migration (Program Chair, college-scoped, with old-to-new equivalencies) already carries
their subjects over. The student's request form is for **subjects taken at another school**.

**6. Data.** `transferee_credits` gains `requested_by`, `endorsed_by`, `endorsed_at`, and
`credited_units` widens to `decimal(4,1)` (a student may enter 1.5; `subjects.units` already
went fractional). The status column is a plain string, so `endorsed` needs no enum change.

## Alternatives considered

- **Keep Registrar Staff as the owner.** Contradicts the request and gives the people who
  know the curriculum no part in the mapping.
- **Store the suggestions, or map the best one automatically.** A stored guess is
  indistinguishable from a decision and would quietly credit subjects nobody chose.
- **Approve without an endorsement step.** Leaves no record that the Program Chair has
  finished mapping, and lets Registrar Staff approve a credit that has no subject yet.
- **Count an approved credit only as a record (as before).** Rejected by the stakeholder's
  decision: crediting a subject that changes nothing defeats the point.
- **Count it through a converted GRC grade.** Needs the official equivalence rule PRD §17
  still leaves open.

## Consequences

- **Credits approved before this change that are mapped to a subject now count as
  credited.** Under the old flow Registrar Staff could approve a `pending` credit that had a
  `subject_id`; those rows are `approved` and start taking effect. Rows still `pending` (for
  example ones Registrar Staff recorded) now wait for the Program Chair. Review the dev and
  production data before deploying.
- The Registrar Staff Credit Mappings workspace no longer has a "Record a transferee credit"
  form; it lists what the Program Chair has endorsed. The Program Chair has a new workspace
  (requests to review with suggestions, a manual subject picker, endorse or decline, and a
  walk-in form).
- One subject maps to one GRC subject (`subject_id` stays 1:1). A source subject that
  satisfies several is not modelled.
- Tests: `TransfereeCreditsEndpointTest` (ownership, college scope, chair-cannot-approve,
  registrar-only-endorsed, idempotency, notifications, suggestions),
  `ResolveCreditedSubjectIdsTest`, and the effect tests in `EligibleSubjectsEndpointTest`,
  `ClassifyEnrollmentStandingTest` and `ProspectusEndpointTest`; frontend workspace, dialog
  and role tests.

## Amendment 2026-10-04 — drafts, the Registrar Head decides, and returns

**Status:** Accepted. Slice 1 of the transferee flow (`docs/superpowers/specs/2026-10-04-transferee-credit-mapping-pay-first-design.md`).

- **A Student saves a private list and sends it.** A Student's credit is created as `draft`: it is visible only to them, grants nothing and notifies nobody. `POST /api/v1/transferee-credits/submit` turns every draft into `pending` (`submitted_at`) and sends the Program Chairs of the student's college one notice with the count; a repeat changes nothing. A Student may edit or remove their own draft (`PATCH`, `DELETE`); a sent credit can no longer be removed. A Program Chair's own recorded credit is still `pending` at once.
- **The Registrar Head, not Registrar Staff, decides.** `decide` (`approve`, `reject`) moves from Registrar Staff to the Registrar Head. Registrar Staff keep read-only visibility of credits that have been sent. Credits already `endorsed` when this ships become the Registrar Head's queue; no data migration is needed.
- **`return`.** The Registrar Head may send an `endorsed` credit back to the Program Chair with a reason (`endorsed` → `pending`; `returned_by`, `returned_at`, `return_reason` recorded; the endorsement is cleared; the Program Chairs are notified). The Head does not edit a mapping. Endorsing again clears the return details.
- **Unchanged:** the Program Chair maps and endorses; suggestions are advice and are never stored; an approved, mapped credit counts as credited without a GRC grade; PRD §17 stays open.
- **Superseded wording:** "Registrar Staff approve or reject an endorsed credit" in Decisions 1 and 3 and in the Consequences about the Registrar Staff workspace now reads Registrar Head; that workspace stays a read-only view for Registrar Staff.
- **Migration:** `2026_10_04_000004_add_submission_and_return_to_transferee_credits_table` (four nullable columns). The owner runs it on the dev database and on Hostinger; it is not applied by the code change.
- **Not in this slice:** the Program Chair's side-by-side mapping screen and bulk "submit to Registrar Head" (Slice 2), the queue number and pay-first enrollment (Slice 3), reading an uploaded TOR (Slice 4).

## Amendment 2026-10-04 (second) — side-by-side screens and the student-level steps

**Status:** Accepted. Slice 2 of the transferee flow (`docs/superpowers/plans/2026-10-04-transferee-credit-side-by-side-mapping-and-head-review.md`).

- **One student at a time.** The Program Chair's workspace lists the students with credits waiting for a mapping and opens one student on a side-by-side screen: the student's entry on the left (code, title, school, school year, semester, grade, units, and a button to read the uploaded TOR) and the matching subject of the student's own curriculum on the right, in a searchable dropdown. The per-credit review dialog is gone; its abilities (suggestions, a manual subject, credited units, declining with a reason, the API's own error text) are in the new screen ("No equivalent" is the decline).
- **The screen pre-selects, it never saves.** The best suggestion is pre-selected on each row and marked "Suggested", and two rows never pre-select the same subject. Nothing is written until the Chair clicks Confirm on a row (or Confirm all suggested, a Chair action over the pre-selected rows). `subject_id` is still only ever the Chair's decision; a reload shows the rows unconfirmed again. Decision 2 above is unchanged: the API does not write a guess into `subject_id`.
- **Three student-level endpoints** (policy-gated, no `role:` middleware), next to the per-credit ones, which stay for single steps: `GET /api/v1/student-profiles/{id}/transferee-credits/suggestions` (the Chair, own college: suggestions for every `pending` credit and the curriculum's subjects, computed together by `SuggestCreditSubjects::forCredits`), `POST .../submit-to-registrar-head` (the Chair: every `pending` credit becomes `endorsed`, all or nothing, 422 naming the credits still unmapped, idempotent, one notice to the Registrar Head), and `POST .../approve-all` (the Registrar Head: every `endorsed` credit becomes `approved`, idempotent, one notice to the student). Drafts and decided credits are never touched by them.
- **The Registrar Head has his own workspace**: students with endorsed credits, the same side-by-side layout read-only, Return and Reject per row (a reason is required) and Approve all for the student. Registrar Staff keep the read-only table.
- **No migration.** Nothing in this slice changes the schema.

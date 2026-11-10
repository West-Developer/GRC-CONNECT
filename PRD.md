# Product Requirements Document (PRD)

## Automated Enrollment System with Predictive Analytics

**Client:** Global Reciprocal Colleges (GRC)  
**Product Type:** Secure, API-first web application  
**Architecture:** Next.js + React + TypeScript client application and Laravel REST API  
**Revision:** v3.3 — Super Admin Role and Department Switcher  
**Status:** Implementation specification aligned to the approved capstone manuscript and current project instructions

---

## Revision Summary

### v3.3 — Super Admin Role and Department Switcher (2026-10-01)

A new `super_admin` role adds cross-departmental oversight, emergency intervention, and a central Accounts & Access console without being a tenth primary institutional actor (see ADR 0038). The Super Admin is provisioned exclusively via CLI (`php artisan super-admin:provision`) configured by `SUPER_ADMIN_EMAIL` — never via the default seeders. An acting-context mechanism lets the Super Admin temporarily assume any of the nine institutional offices; every action in an acting context carries both the real user ID and the acting role/college in the audit log. A Department Switcher UI (topbar dropdown, mobile Sheet, amber banner) and an Accounts & Access workspace (invite, role change, status toggle, session revocation, permanent deletion for unused accounts) complete the console. Two new column pairs are added: `acting_role`/`acting_college` on `personal_access_tokens` and on `audit_logs`. See `docs/runbooks/super-admin.md` for provisioning and incident-response procedures.

### v3.2 — Presentation Layer Realigned to Next.js (2026-07-28)

The Presentation Layer moves from a Vite-based React SPA to **Next.js with React and strict TypeScript**, reverting v3.1's deliberate substitution and realigning the implementation with the capstone manuscript's own architecture diagram. Sections changed: the header, §1.2, §6.1, §7, and §7.3.

Nothing else changes. The three-tier Client-Server model, the Laravel REST API boundary, the MySQL data layer, bearer-token authentication (§9.1), the four DFD processes, the nine actors, and every functional requirement are unaffected. Next.js is used as a client-rendered application only: no server-side session, no server-side rendering of authorized student data, no API proxying. See ADR 0013 for the full decision record.

### v3.1 — Capstone Manuscript Alignment, API-First Stack, and Delivery Controls

This revision refines PRD v3 after reviewing the April 2026 capstone manuscript, *A Development of an Automated Enrollment System with Predictive Analytics*. It preserves the API-first engineering direction required by the project instructions while aligning the implementation requirements with the manuscript's institutional scope, nine actors, four DFD processes, logical data stores, Agile method, and ISO/IEC 25010 evaluation plan.

1. **Architecture replaced:** The product is now a React + TypeScript SPA backed by a Laravel REST API. Server-rendered PHP pages, native PHP controllers, Bootstrap, session authentication, and direct browser-to-PHP form submissions are removed from the implementation plan.
2. **Authentication replaced:** Protected requests use Laravel Sanctum personal access tokens sent as bearer tokens. Cookie/session authentication and CSRF-cookie flows are not used.
3. **API contract added:** All public application endpoints are versioned under `/api/v1`, use Laravel API Resources, return a consistent error envelope, enforce Policies, and preserve pagination metadata.
4. **Frontend standards added:** Vite, strict TypeScript, Tailwind CSS, shadcn/ui, React Hook Form, Zod, and TanStack Query are required. *(Superseded by v3.2: Next.js with the App Router replaces Vite. Every other item in this list still stands — see §6.1.)*
5. **Enrollment state ambiguity corrected:** Student submission, Registrar approval, Accounting confirmation, and COM generation now follow one explicit state machine.
6. **Section threshold aligned:** The institutional 25-student value is treated as the section-viability threshold, not room capacity. A proposed section below the threshold cannot be published by default; any permitted exception requires authorized approval and a complete audit trail.
7. **Predictive analytics safeguards added:** Forecasts and attrition results are advisory decision support. They must not automatically deny enrollment, remove a student, dissolve a section, or impose punitive action.
8. **Quality gates expanded:** Static analysis, automated tests, accessibility, security, performance, and ISO/IEC 25010 evaluation are required for completion.
9. **Agent workflow tools added:** Superpowers, Frontend Design, GSD, and Context7 are defined as development-time capabilities, not runtime dependencies.
10. **Manuscript-aligned scope retained:** Digital COM generation, the section-demand feedback loop, attrition analytics, honors evaluation, and government compliance reporting remain required.
11. **DFD traceability expanded:** Exact Level 2 subprocess names and the logical data stores `STUDENT RECORDS`, `CURRICULUM AND SCHEDULING`, `ENROLLMENT RECORDS`, and `HISTORICAL DATA` are mapped into the product and schema requirements.
12. **Terminology conflict documented:** The manuscript's later DFDs and use cases require a Digital Certificate of Matriculation (COM), while earlier scope/context text also refers to a Certificate of Registration (COR). COM remains the required Process 3.5 output; GRC must confirm whether COR is a distinct document or a synonym before final document templates are implemented.
13. **Advising boundary added:** Rule-based advising must enforce GRC's restriction that irregular students cannot consume slots reserved for regular block sections when that institutional rule applies.
14. **Evaluation protocol aligned:** The PRD now includes the manuscript's Agile sprint/retrospective method, respondent groups, weighted-mean analysis, and exact five-point Likert interpretation ranges.

---

## 1. Source of Truth and Decision Order

Implementation decisions must use the following order, with each source governing its own domain:

1. **Approved written decisions from authorized GRC stakeholders** for official institutional policy values and exceptions.
2. **The approved capstone manuscript** for institutional business scope, user roles, DFD processes and data flows, terminology, methodology, and evaluation design.
3. **The project instructions** for the required application stack, API architecture, engineering conventions, security, testing, and delivery discipline.
4. **This PRD v3.1** as the implementation synthesis, detailed acceptance-criteria source, and day-to-day development contract.

When sources appear to conflict:

- Preserve the manuscript's approved institutional business requirement unless GRC formally changes it.
- Apply the project instructions to implementation technology and engineering practice. A stack difference does not authorize a scope change.
- Treat this PRD as controlling for implementation detail only when it does not contradict a higher-ranked source in that source's governing domain.
- Choose the smallest maintainable implementation that satisfies the approved requirement.
- Record every material assumption or deliberate divergence in the change summary or an Architecture Decision Record.
- Never invent a policy value when an authorized GRC office must confirm it.

Values requiring confirmation before production include the official passing grade, maximum academic load, honors cutoff, section-viability threshold exceptions, enrollment calendar, token expiration policy, report format, and prediction refresh cadence.

### 1.1 Capstone Manuscript Baseline

The governing manuscript is the April 2026 capstone project titled **A Development of an Automated Enrollment System with Predictive Analytics**, presented to the College of Computer Studies of Global Reciprocal Colleges in Caloocan City.

The implementation must preserve these manuscript boundaries:

- exactly nine primary external actors: Student, Admission Staff, Professor, Program Chair, Dean, Executive Director, Registrar Head, Registrar Staff, and Accounting Staff;
- four primary DFD processes: Establish Pre-Enrollment Schedules (1.0), Process Enrollment and Digital Advising (2.0), Execute Final Approvals (3.0), and Generate Predictive Analytics and Forecasting (4.0);
- four logical data-store domains: `STUDENT RECORDS`, `CURRICULUM AND SCHEDULING`, `ENROLLMENT RECORDS`, and `HISTORICAL DATA`;
- an end-to-end flow from digital advising and prerequisite validation through schedule planning, Registrar approval, payment-queue handling, official document generation, analytics, and compliance reporting;
- Agile delivery using a prioritized Product Backlog, time-boxed Sprints, testing within each increment, and stakeholder retrospectives;
- ISO/IEC 25010 evaluation plus isolated statistical validation of the forecasting engine.

### 1.2 Resolved and Unresolved Manuscript Differences

- **Frontend technology:** The manuscript's architecture diagram references Next.js/React. PRD v3.1 temporarily replaced this with a Vite-based React SPA. **As of v3.2 this is reversed: Next.js with React and strict TypeScript is the approved Presentation Layer**, realigning the implementation with the manuscript's own architecture diagram. The three-tier Client-Server architecture, Laravel REST API, MySQL data layer, and bearer-token authentication are unchanged. Next.js is used as a client-rendered application against the independent Laravel API — it does not render backend data server-side, does not proxy the API, and does not introduce session cookies. See ADR 0013.
- **COM versus COR:** The Level 1 and Level 2 DFDs, proposed workflow, and use case discussion identify **Digital Certificate of Matriculation (COM)** as the Process 3.5 output delivered to the Student Portal after payment confirmation. Earlier scope and context passages also mention **Certificate of Registration (COR)**, including a Registrar Staff view. The implementation must not silently merge the terms. COM is required for Process 3.5; whether COR is a separate artifact remains an open institutional decision.
- **Accounting permissions:** “View-only” applies to academic and enrollment-record content. Accounting Staff may perform only the operational writes explicitly required by the DFD: update the Active Serving Number and record Payment Confirmation. They cannot edit academic data, curriculum, Registrar decisions, or enrollment contents. Stakeholder Doc 14 (ADR 0035) adds one more: Accounting Staff own the fee schedule (tuition rate and miscellaneous fees), which is billing configuration rather than academic data.

---

## 2. Product Overview

GRC's current enrollment process requires old and regular students to complete approximately six to seven sequential steps and may require new or transferee students to complete up to eleven steps across multiple physical or manually coordinated offices. This creates delays, duplicated encoding, unclear status visibility, schedule conflicts, section dissolution risk, and slow administrative reporting.

The Automated Enrollment System with Predictive Analytics centralizes pre-enrollment planning, digital advising, prerequisite validation, section and faculty scheduling, approval routing, payment-queue confirmation, COM generation, academic records, predictive decision support, and compliance reporting in one role-governed platform.

### 2.1 Product Goals

- Reduce manual routing and repeated encoding during enrollment.
- Give students a clear digital enrollment journey and status.
- Validate prerequisites and schedule conflicts consistently.
- Improve section planning using historical and current demand data.
- Support faculty availability, loading, schedule approval, and publication.
- Provide auditable Registrar and Accounting workflows.
- Generate the Digital Certificate of Matriculation after confirmed payment.
- Surface attrition, honors, enrollment, and compliance information to authorized roles.
- Meet ISO/IEC 25010 quality characteristics and GRC's evaluation process.

### 2.2 Success Measures

Target values must be baselined during pilot testing.

- Reduced average completion time from advising to confirmed enrollment.
- Reduced number of manual handoffs and repeated data entries.
- Reduced prerequisite, duplicate-enrollment, capacity, and schedule-conflict errors.
- Percentage of enrollment transactions whose status is visible without staff follow-up.
- Forecast accuracy using MAE/RMSE for section demand.
- Attrition model precision, recall, F1 score, and calibration.
- UAT satisfaction scores mapped to ISO/IEC 25010.
- Percentage of critical user journeys passing automated end-to-end tests.
- Number and severity of authorization, data-integrity, and security defects before deployment.

### 2.3 Non-Goals

The first production release does not include:

- Direct e-wallet, bank, card, or online payment-gateway integration.
- Biometric, RFID, turnstile, or physical access-control integration.
- Fully automated room optimization using genetic algorithms.
- Automatic disciplinary, denial, expulsion, or section-dissolution decisions based solely on a prediction.
- Public student self-registration unless separately approved by GRC.
- A native mobile application.
- Generative AI for academic decisions.

---

## 3. Users and Role-Based Access Control

The system supports nine primary roles. Access must be enforced in both Laravel Policies and the authorization-aware frontend. Hiding a control in the SPA is not sufficient authorization.

### 3.1 Student

- View enrollment status and timeline.
- Review eligible subjects and recommendation rationale.
- Select subjects from available published sections.
- Submit the enrollment request.
- View a live, privacy-preserving queue ticket and payment status in the
  normal portal.
- Claim a queue ticket only at the authorized Cashier kiosk; the normal portal
  does not expose a claim action.
- View grades and approved academic history.
- Save the subjects completed at another school as a list, submit it for credit mapping, and follow its status.
- A newly created Transferee or Returnee sees only Enrollment and Grades (their Prospectus and grades), with a notice that the account is deactivated 7 days after Admission created it unless their enrollment is finished and paid by then; the other options and the notice go away once they are enrolled and have paid (ADR 0048, amended by ADR 0050). Once the Program Chair has passed their credit mapping to the Registrar Head they can enroll: the credits count provisionally, the Prospectus marks them as awaiting the Registrar Head's approval, and the Regular or Irregular category follows from them: a subject they are credited for is taken out of their block, which makes them Irregular and enrolling per subject (ADR 0051). Admission's setup invitation admits a deactivated student again for another 7 days (ADR 0048).
- Download or print the Digital COM after payment confirmation.
- Manage permitted profile fields and password.
- View notifications and enrollment history.

### 3.2 Admission Staff

- Create new student accounts and initial profiles.
- Record and view admission status.
- Issue a temporary credential or approved invitation flow. For a **freshman** the invitation
  is not sent at intake: Admission issues a Cashier **queue number** instead and emails it with a
  link to a live view of the queue (ADR 0044); the account setup invitation is sent only after the
  Cashier confirms the freshman's first payment (ADR 0043).
- View only the admission information required for the role.
- Cannot edit grades, curriculum rules, payments, or Registrar approvals.

### 3.3 Professor / Faculty

- Submit availability and ranked subject preferences.
- View assigned teaching schedules and class rosters.
- Encode and submit final grades for assigned sections only.
- View schedule-publication notifications.
- Cannot access student payment details or Registrar overrides.

### 3.4 Program Head (role key `program_chair`)

- Manage program curriculum mappings, subjects, prerequisites, and term offerings.
- Configure planned sections, capacities, schedules, and faculty assignments.
- Review unassigned-subject alerts and section-demand forecasts.
- Compare forecasted and actual demand.
- Submit proposed schedules for approval.
- Approve or reject the irregular and overload enrollments of their own college before they reach the Registrar (ADR 0030).
- Map students' transferee credit requests to subjects of their curriculum, using the system's suggestions or a manual choice, and endorse them to the Registrar Head (ADR 0026, amended).

### 3.5 Dean

- Review and approve or return proposed schedules.
- View the real-time enrollment dashboard for their college (Doc 14 replaced the separate stuck-student list with the dashboard's "Ongoing" group; the older stuck-student endpoint is unused and pending removal).
- View honors evaluation results.
- View approved digital curriculum information.
- Export authorized reports.

### 3.6 Executive Director

- Perform final authorization and locking of the master schedule.
- View institution-level enrollment and curriculum dashboards.
- View high-level KPIs and year-over-year comparisons.
- Cannot alter detailed student academic records unless separately authorized.

### 3.7 Registrar Head

- Review and approve enrollment submissions, and open a student's profile from the approvals queue.
- Approve, reject or return transferee credit mappings that the Program Chair has endorsed (ADR 0026, amended).
- Perform logged override or void actions for authorized edge cases.
- Withdraw an enrolled student from individual subjects with a reason: the subject leaves the enrollment, its seat is released, and the Prospectus shows it as W (Withdrawn) until the student takes it again (ADR 0056).
- View predictive attrition analytics.
- Generate government compliance reports.
- Preview and download, as CSV or as CHED's own template filled in (Excel), the CHED Enrollment List and the NSTP Form 2-B (enrollment and graduates) and Form 2-A (summary) for a term; every view and download is audited (ADR 0049).
- View audit logs and daily control reports.
- Configure approved Registrar-controlled policy values where the product supports configuration. The fee schedule is no longer one of them: it moved to Accounting Staff (ADR 0035); the Registrar Head can still read it.

### 3.8 Registrar Staff

- Approve or reject enrollment submissions (regular, and irregular once the Program Head has approved them).
- View transferee credit mappings that have been sent (read-only; the Registrar Head decides them, ADR 0026 amended).
- Process dropping and withdrawal requests.
- View permitted academic records and enrollment documents.
- Cannot execute Registrar Head overrides.

### 3.9 Accounting Staff

- View the pending-payment queue without editing academic, curriculum, or Registrar-controlled enrollment data.
- Manage the active serving number.
- Record confirmation of an externally received payment as an operational write.
- Maintain the fee schedule: the tuition rate per unit and the miscellaneous fees, with every change audited (ADR 0035).
- Trigger idempotent enrollment finalization and Digital COM generation through that payment-confirmation action.
- Cannot change academic records, curriculum data, or Registrar decisions.

### Queue kiosk device identity

`queue_kiosk` is a non-human, shared device identity for the dedicated
Cashier kiosk. It is not a tenth primary external actor and does not renumber
the nine primary institutional actors above; the already documented IT role is
also unchanged. The device owns no Student record and is never the actor for a
Student claim. It is used only on `/queue` to prove that a Student's claim is
being made at an authenticated kiosk.

Accounting Staff may view and rotate the shared kiosk credential through its
authorized workspace. The device itself may validate or end its own session,
but cannot use ordinary portal APIs.

### Super Admin system role

`super_admin` is a non-primary, system-level administrative role. It is not an
eleventh external actor and does not renumber the nine primary institutional
actors above. The Super Admin exists solely to provide cross-departmental
oversight, emergency intervention, and account lifecycle management for the
platform itself.

Key boundaries:
- Provisioned only via `php artisan super-admin:provision <email>` driven by
  `SUPER_ADMIN_EMAIL`; never created through the default database seeders.
- Cannot act with ambiguous privileges: when acting on behalf of an office, the
  active token carries an explicit acting role/college that controls all
  authorization decisions for that session.
- Every action in an acting context is attributed to the real Super Admin user
  and the acting role/college in the immutable `audit_logs`.
- Account management operations (invite, role change, status toggle, delete) are
  available only in Console mode (no active acting context).

See ADR 0038 for the full decision record and `docs/runbooks/super-admin.md` for
provisioning and incident-response procedures.

### IT Control managed-account password changes

IT Control may set a new password for an active Student or Faculty account that
has completed account setup (owner request, 2026-11-05; ADR 0054 amendment).
`POST /api/v1/it-control/accounts/{user}/password` requires a confirmed password
under the shared strength policy. It cannot manage staff/admin roles, activate
an account, or bypass freshman payment and setup requirements. The transaction
hashes the new password, revokes the target's sessions and unused authentication
codes, clears its prior OTP verification grace, and audits the actor and target
without recording passwords or hashes. Subsequent sign-in follows the existing
OTP policy. The Student and Faculty row actions provide a masked Change password
dialog; account setup, status, enrollment, grades and payment records are retained.

---

## 4. Core User Journeys and State Machines

### 4.1 Schedule Lifecycle

`draft → dean_approved → executive_approved → published → closed`

A returned proposal moves back to `draft` with a required reason. Every transition is authorized and audited. A proposed section whose forecasted demand is below the confirmed institutional viability threshold—currently documented as 25 students—must not be published unless an authorized exception workflow exists, the required approvers accept it, and the reason is permanently audited.

### 4.2 Enrollment Lifecycle

`draft → pending_registrar_approval → pending_payment → enrolled`

An irregular or overload submission first passes the Program Head:

`draft → pending_program_head_approval → pending_registrar_approval → pending_payment → enrolled`

If the Program Head changes the subjects of that submission, it goes back to the student first:

`pending_program_head_approval → pending_student_review → pending_registrar_approval` (the student accepts)

or `pending_student_review → pending_program_head_approval` (the student does not accept, giving a reason) (ADR 0040).

A transferee who has not paid yet waits at a payment hold instead of joining the Registrar's queue
(ADR 0043, amended 2026-10-05): wherever the flow above would reach `pending_registrar_approval`, they
reach `awaiting_first_payment` first, and the Cashier's confirmed first payment moves them on to
`pending_registrar_approval`. Every other student follows the flow above.

Alternative terminal or exception states:

- `rejected`
- `cancelled`
- `withdrawn`

Rules:

1. A student saves subject choices as `draft`.
2. Submission performs authoritative validation and creates `pending_registrar_approval`,
   or `pending_program_head_approval` for an irregular or overload submission, which
   the Program Head of the student's college approves or rejects (with a reason) before
   it joins the Registrar's queue (ADR 0030). If the Program Head changes the subjects, the
   enrollment waits at `pending_student_review` with the Program Head's reason until the student
   accepts (it then joins the Registrar's queue) or declines with a reason (it returns to the
   Program Head) (ADR 0040).
3. The system does not issue a queue ticket until Registrar approval has made
   the enrollment `pending_payment`; the Student then claims one at the
   Cashier kiosk, or Accounting Staff may issue one on the Student's behalf.
4. Registrar Staff or Registrar Head approval changes the enrollment to `pending_payment`
   for every enrollment, regular or irregular, and creates its assessment.
5. Accounting confirmation changes it to `enrolled`. A payment below this term's assessment needs a
   promissory note on file only when the student carries a previous bill (an unpaid balance from another
   term); a downpayment with nothing owed from before needs none (ADR 0047).
6. The same database transaction creates or confirms the Digital COM record.
7. Rejection, cancellation, and withdrawal require a reason and audit entry.
8. Repeated requests must not duplicate seats, payments, queue tickets, notifications, or documents.

For a Student, a pending-payment ticket is claimed at the Cashier kiosk, using
the Student's own bearer token plus authenticated kiosk proof. Accounting
Staff may continue to issue a ticket on a Student's behalf as part of the
front-desk workflow.

**Freshmen pay first (ADR 0043).** A freshman has no enrollment and cannot sign in when they
reach the Cashier, so the steps above do not apply to them. Admission issues the freshman's queue
ticket at intake — it belongs to the student profile, not to an enrollment, and draws from the
same open queue cycle and `Q%03d` sequence — and emails the number with a link to the live queue.
The Cashier sees that the person being served is a freshman. The Cashier confirms the freshman's
first payment with `POST /api/v1/student-profiles/{id}/freshman-payment` (the dialog first reads
`GET .../freshman-payment-preview`, which names the section the freshman will get and the estimated
assessment and writes nothing). The payment is at least PHP 1,000 (`enrollment.freshman_payment.minimum_amount`)
and never needs a promissory note; the unpaid balance stays on the student's account.

- **A section has open seats** (the first block, in block-number order, whose every subject has a
  seat; only for the Regular enrollment category): the payment is held as credit and the freshman is
  submitted to it as `pending_registrar_approval`; the queue ticket is served. The payment does not
  enroll them: Registrar Staff approval does, which makes the COR and sends the one enrollment
  confirmation (ADR 0043, amended 2026-10-04). After the commit the account setup invitation is emailed.
- **Every section is full:** the payment is still accepted and held as advance credit, the ticket is
  served and the account setup invitation is emailed. When a section is published or gains seats the
  freshman is notified (in-app and email); they enroll in it on the Enrollment page, and when Registrar
  Staff approve it the held credit completes the enrollment without a second payment.
- Confirming again is a 200 that changes and sends nothing (`student_profiles.freshman_payment_confirmed_at`
  is the check-first idempotency key).

**Transferees pay first too (ADR 0043, amended 2026-10-05).** A transferee lists the subjects they took at
another school (saved as drafts, then sent to the Program Chair), the Program Chair maps each one on a
side-by-side screen, and the Registrar Head approves (ADR 0026, amended). When the last open credit closes
(approved, rejected or declined) the transferee is given a queue number — the same kind of profile-linked
ticket, issued once, emailed after commit with the live-queue link — and signs in, as they already have an
account, to choose their subjects. A regular submission, or an Irregular one once the Program Chair has
approved it, waits at `awaiting_first_payment`: it reserves its seats and the Registrar does not see it.
The Cashier confirms the first payment through the same endpoints as a freshman's (the preview shows the
subjects the transferee chose; same PHP 1,000 minimum, no promissory note): the money is held as credit, the
enrollment moves to `pending_registrar_approval` and the ticket is served; Registrar approval then enrolls
them from the held credit, makes the COR and sends the one confirmation. A transferee who pays before choosing
has the payment held and, once they submit, goes straight to the Registrar. Approving credits re-derives
Regular or Irregular. `student_profiles.freshman_payment_confirmed_at` is the idempotency key for both types.

Registrar Staff and the Registrar Head have a read-only list of freshmen enrollees
(`GET /api/v1/freshman-enrollees`: enrolled, awaiting approval, awaiting a section, awaiting payment).
The full decision record is ADR 0043.

### 4.3 Grade Lifecycle

`draft → submitted → locked`

- Professors may edit only draft grades for their assigned sections.
- Submission validates the complete roster and records the submitter and time.
- Locking follows the approved Registrar policy.
- Corrections after locking require an authorized, audited workflow.
- GWA excludes normalized subject codes beginning `NSTP`, `PATHFIT`, or `PE`
  (spaces and case ignored). These subjects remain on the academic record and
  require grade submission, but do not contribute units or weighted points.
- Dean's List is computed live after every non-dropped enrolled subject has a
  submitted or locked grade; its inclusive unrounded GWA range is 1.00–1.50.

### 4.4 Prediction Lifecycle

`queued → running → succeeded | failed`

- The latest successful result remains available if a new run fails.
- Dashboards show generated time, model version, and freshness.
- Predictions never directly mutate enrollment or academic status.

---

## 5. Functional Scope Aligned with the DFD Processes

### 5.1 Process 1.0 — Establish Pre-Enrollment Schedules

The system captures curriculum rules, faculty availability, subject preferences, planned sections, capacities, and approval decisions. The following DFD names are mandatory traceability labels, even when implementation classes use more specific names:

- **1.1 Define Curriculum Capacities** — receives Curriculum Mapping, Section Capacities, and the closed-loop Section Demand Forecast.
- **1.2 Gather Faculty Input** — receives Professor Availability and Subject Preferences and produces Faculty Constraints.
- **1.3 Generate and Validate Schedule** — produces conflict-free Proposed Master Schedules and Loads for Dean review.
- **1.4 Approve and Publish Schedule** — applies Dean approval and Executive Director authorization, stores the final schedule, and distributes teaching schedules, rosters, status, and alerts.

The Program Chair must see the Section Demand Forecast produced by Process 4.0 before finalizing the proposed section plan. This closes the planning feedback loop while preserving human approval.

#### Requirements

- **FR-SCH-001:** Manage programs, curricula, subjects, units, year levels, terms offered, and prerequisites.
- **FR-SCH-002:** Reject direct or transitive prerequisite cycles.
- **FR-SCH-003:** Record faculty availability and ranked subject preferences.
- **FR-SCH-004:** Create sections with separate values for capacity and section-viability threshold.
- **FR-SCH-005:** Detect room, faculty, section, and student-time conflicts where relevant data exists.
- **FR-SCH-006:** Show forecasted demand, confidence or error context, and last-updated time.
- **FR-SCH-007:** Route proposed schedules through Dean and Executive Director approval.
- **FR-SCH-008:** Require a reason when returning or overriding an at-risk section.
- **FR-SCH-009:** Publish authorized schedules and notify affected faculty and roles.
- **FR-SCH-010:** Audit every schedule change and status transition.

#### Acceptance Criteria

- A Program Chair cannot create a prerequisite cycle.
- A Dean cannot approve a schedule that is not in `draft`.
- An Executive Director cannot approve a schedule not approved by the Dean.
- Publishing exposes the schedule to permitted students and professors.
- A proposed section below the institutional viability threshold is blocked from publication by default.
- A permitted exception records the actor, authority, reason, previous value, new value, approval chain, and time.

### 5.2 Process 2.0 — Enrollment and Digital Advising

The system validates the student's academic history against curriculum and prerequisite rules, produces an eligible-subject pool, ranks appropriate available choices, checks conflicts and capacity, and supports submission. DFD traceability requires these subprocesses:

- **2.1 Authenticate and Read Profile** — initializes approved new-student profiles, returns Admission Status View, and establishes the authenticated student context.
- **2.2 Validate Capacities and Prerequisites** — cross-references `STUDENT RECORDS` with `CURRICULUM AND SCHEDULING` and outputs the Eligible Subject Pool.
- **2.3 Generate Predictive Recommendation** — formats a rule-compliant recommended academic load from the eligible pool and student profile.
- **2.4 Finalize Subject and Generate Queue Ticket** — records the verified
  Subject Selection as a Pending PEF Record in `ENROLLMENT RECORDS`; after
  Registrar approval, the pending-payment enrollment can receive one queue
  ticket through the Cashier kiosk or Accounting Staff's on-behalf flow.

#### Requirements

- **FR-ENR-001:** Show only subjects applicable to the student's active program and curriculum.
- **FR-ENR-002:** Validate prerequisites using the official grading policy.
- **FR-ENR-003:** Exclude unpublished, full, conflicting, completed, or otherwise ineligible sections.
- **FR-ENR-004:** Enforce the official maximum-unit and overload policy.
- **FR-ENR-005:** Provide an understandable reason for included, excluded, and recommended subjects.
- **FR-ENR-006:** Preserve a student's valid selections when validation errors occur.
- **FR-ENR-007:** Submit enrollment atomically and prevent duplicate active enrollments.
- **FR-ENR-008:** Allow exactly one unique queue ticket to be claimed for an
  eligible pending-payment enrollment, or issued by Admission to a freshman who has no enrollment
  yet (one per student profile, ADR 0043).
- **FR-ENR-009:** Show a Digital PEF or equivalent enrollment summary before final submission.
- **FR-ENR-010:** Provide real-time or refreshed status updates without a full application reload.
- **FR-ENR-011:** Enforce approved block-section eligibility rules, including preventing irregular students from reserving slots designated exclusively for regular block students.

#### Acceptance Criteria

- A failed prerequisite removes the dependent subject and displays the reason.
- A full section cannot be selected.
- Two conflicting sections cannot be submitted together.
- A duplicate submission does not increment seat reservations twice.
- An irregular student cannot submit a regular-block section when GRC policy reserves that section for regular students.
- A successful submission creates exactly one enrollment, the selected
  enrollment subjects, one audit entry, and the appropriate notification; it
  does not issue a queue ticket before Registrar approval and a valid claim.
- A server-side validation failure returns `422` and maps errors to the corresponding form fields.

### 5.3 Process 3.0 — Execute Final Approvals, Payment Queue, Withdrawal, and COM

This process handles grade encoding, Registrar decisions, transferee credits, dropping and withdrawal, manual payment confirmation, and Digital COM generation. DFD traceability requires these subprocesses:

- **3.1 Encode Final Grades** — accepts Professor-encoded grades into `STUDENT RECORDS` for academic history, failure-based attrition metrics, and honors evaluation.
- **3.2 Process Transfers and Withdrawals** — records transferee credits and withdrawal/drop data and forwards attrition-relevant events to Process 4.0.
- **3.3 Execute Final Approvals** — applies Registrar Head final approval or override commands, computes the approved assessment, and updates the Final Enrollment Status.
- **3.4 Manage Real-Time Payment Queue** — exposes the Pending Payment List, accepts Active Serving Number changes, and receives Payment Confirmation.
- **3.5 Generate COM** — uses Payment Confirmation and validated enrollment data to generate the Digital Certificate of Matriculation and route it to the Student Portal.

#### Requirements

- **FR-FIN-001:** Provide a Registrar approval queue with filters and pagination.
- **FR-FIN-002:** Require authorization and a reason for rejection, override, void, or forced status change.
- **FR-FIN-003:** Map transferee credits without bypassing audit and academic-record controls. A Student requests, the Program Chair maps and endorses, and the Registrar Head approves, rejects or returns; an approved credit counts as credited without a GRC grade (ADR 0026, amended).
- **FR-FIN-004:** Process dropping or withdrawal exactly once and release seats when policy requires.
- **FR-FIN-005:** Show Accounting only approved `pending_payment` enrollments.
- **FR-FIN-006:** Maintain the active serving number and queue order.
- **FR-FIN-007:** Confirm an externally received payment without integrating a payment gateway.
- **FR-FIN-008:** Generate the Digital COM after payment confirmation.
- **FR-FIN-009:** Make confirmation and COM generation idempotent.
- **FR-FIN-010:** Allow the student to view and print/download the authorized COM.
- **FR-FIN-011:** Offer a full-payment discount (10% of tuition plus miscellaneous fees by default) when the Cashier collects the whole net assessment in one payment. An installment carries no discount and no extra fee, and the discount does not stack with a scholarship (ADR 0046).
- **FR-FIN-012:** Let the Registrar Head set the discount percentage and its validity window (valid-from and valid-until dates). Every change is audited and never alters an issued COR (ADR 0046).

#### Acceptance Criteria

- Registrar approval moves an enrollment from `pending_registrar_approval` to `pending_payment`.
- Accounting cannot confirm an enrollment in any other state.
- Confirming payment twice does not create duplicate records or documents.
- Payment confirmation, enrollment finalization, COM creation, audit logging, and notification occur in one transaction or a safely retryable workflow.
- A withdrawal cannot decrement section enrollment more than once.
- Every override includes a non-empty reason and is visible in the Registrar Head audit log.

### 5.4 Process 4.0 — Predictive Analytics and Compliance

Sub-processes retained from the manuscript-aligned scope:

- **4.1 Aggregate Current Data**
- **4.2 Execute Predictive Analytics**
- **4.3 Publish Forecasts and Dashboards**
- **4.4 Analyze Attrition Metrics**
- **4.5 Identify Academic Honors**
- **4.6 Generate Government Reports**

#### Requirements

- **FR-ANL-001:** Produce section-demand forecasts per term and relevant subject/program grouping.
- **FR-ANL-002:** Return the forecast to Process 1.0 for Program Chair planning.
- **FR-ANL-003:** Produce authorized enrollment dashboards and stuck-student reports.
- **FR-ANL-004:** Produce attrition risk indicators for Registrar Head use.
- **FR-ANL-005:** Explain major contributing factors in plain, non-punitive language where technically feasible.
- **FR-ANL-006:** Evaluate honors using approved deterministic academic rules, not an opaque prediction.
- **FR-ANL-007:** Generate approved government or CHED-style reports. The CHED Enrollment List and NSTP Forms 2-B / 2-A are built from CHED's own templates and downloaded as CSV or Excel by the Registrar Head (ADR 0049); the official report fields and sign-off remain an open decision in §17.
- **FR-ANL-008:** Cache the latest successful result and degrade gracefully when prediction is unavailable.
- **FR-ANL-009:** Store model version, feature schema version, run status, metrics, and generated time.
- **FR-ANL-010:** Restrict student-level attrition data to explicitly authorized roles.
- **FR-ANL-011:** Never automatically deny enrollment, remove a student, or label a student publicly based on risk.
- **FR-ANL-012:** Support audit and evaluation of model performance over time.

#### Acceptance Criteria

- A failed prediction run does not break the dashboard.
- The dashboard displays the most recent successful result and freshness note.
- Section forecasts are visible to the Program Chair before schedule approval.
- Student-level attrition results are not returned to unauthorized users.
- Honors results reproduce the approved policy calculation for test records.
- Compliance exports contain only approved fields and log the requesting user.

### 5.5 DFD Logical Data-Store Traceability

The manuscript's data stores are logical domains, not necessarily single physical tables. Every API use case and database migration must trace to one or more of these domains without orphaned or arbitrarily renamed data flows:

- **`STUDENT RECORDS`** — identity-linked student profiles, academic history, official grades, academic standing, and approved transferee-credit outcomes.
- **`CURRICULUM AND SCHEDULING`** — programs, curricula, subjects, prerequisites, faculty constraints, sections, schedule proposals, approvals, and published rosters.
- **`ENROLLMENT RECORDS`** — Pending PEF records, selected sections, enrollment status, assessments, queue tickets, payment confirmation, withdrawals, and official enrollment documents.
- **`HISTORICAL DATA`** — term-level aggregates, prior enrollment volumes, model-training snapshots, approved analytical outcomes, and feedback data used to recalibrate future forecasts.

The Data Dictionary must document the DFD data packet, owning logical store, physical tables/fields, producing process, consuming process, sensitivity classification, and retention rule.

---

## 6. Required Technology Stack

Exact versions must be recorded at implementation time after checking official compatibility. Use production-stable releases only; do not use alpha, beta, release-candidate, nightly, or preview packages.

### 6.1 Frontend

- Next.js (App Router)
- React
- TypeScript with strict mode
- Tailwind CSS
- shadcn/ui
- React Hook Form
- Zod
- TanStack Query
- A small authentication context or store only for client state that cannot remain local
- A shared HTTP client with token and error interceptors

Routing is provided by the Next.js App Router; no separate routing library is used. Because authentication is bearer-token only (§9.1), the token is not readable by Next.js middleware — route guards are client-side, and server components must not be used to fetch authorized student data.

### 6.2 Backend

- A production-stable PHP release supported by the chosen Laravel release
- Current production-stable Laravel release compatible with the chosen PHP version
- Laravel Sanctum personal access tokens
- Laravel API Resources and Resource Collections
- Form Requests
- Policies and Gates
- Actions or Services for use cases and transaction boundaries
- Jobs and queues for long-running or retryable work
- Structured logging without secrets or unnecessary personal data

### 6.3 Database

- Supported MySQL 8 LTS release
- InnoDB
- `utf8mb4`
- Strict SQL mode
- UTC timestamps in storage
- Reversible Laravel migrations
- Deterministic factories and seeders for development and testing

### 6.4 Predictive Service

Preferred implementation:

- Python service in `ml-service/`
- Production-stable Python release
- pandas
- scikit-learn Random Forest for section-demand forecasting
- XGBoost for attrition classification
- joblib or an equivalent safe model artifact format
- A small internal HTTP API using a production-appropriate Flask or FastAPI setup
- Network access restricted to the Laravel backend
- Versioned prediction request and response schemas

Direct `shell_exec()` invocation is not the target architecture. It may be used only as a documented local-development fallback if the internal service cannot be run, and it must never accept unsanitized command content.

### 6.5 Quality Tooling

- Laravel Pint
- PHPStan with Larastan
- Pest or PHPUnit
- ESLint
- Prettier
- TypeScript compiler checks
- Vitest
- React Testing Library
- Playwright
- MySQL-backed integration tests
- Dependency and secret scanning supported by the repository host

---

## 7. System Architecture

The manuscript's three-tier Client-Server model remains mandatory: a Presentation Layer, a Laravel REST API Application Layer, and a centralized MySQL Data Layer connected to the predictive engine. The manuscript illustrates Next.js/React in the Presentation Layer, and the Presentation Layer is implemented in Next.js with React and strict TypeScript accordingly. This does not change the DFD scope, RBAC boundaries, or required data flows.

Next.js is used strictly as a client-rendered application. The Presentation Layer holds no server-side session, performs no server-side rendering of authorized student data, and does not proxy or re-export the Laravel API. Authentication remains bearer-token only per §9.1, which means route protection is enforced client-side in the Presentation Layer and authoritatively by Laravel Policies on every request — hiding a control in the client is not authorization. See ADR 0013.

### 7.1 Repository Layout

```text
GRC-ENROLLMENT/
  frontend/
    src/
      app/
        components/
          ui/
          auth/
          common/
          features/
            admissions/
            analytics/
            curriculum/
            enrollment/
            faculty/
            payments/
            registrar/
            schedules/
          pages/
        hooks/
        lib/
        routes/
        schemas/
        services/
        store/
        types/
      styles/
      main.tsx
    public/
    tests/
    package.json
    vite.config.ts
    tsconfig.json

  backend/
    app/
      Domain/
        Admissions/
        Analytics/
        Curriculum/
        Enrollment/
        Payments/
        Registrar/
        Scheduling/
        Shared/
      Http/
        Controllers/Api/V1/
        Requests/Api/V1/
        Resources/Api/V1/
      Models/
      Policies/
      Providers/
      Support/
    database/
      factories/
      migrations/
      seeders/
    routes/
      api.php
    tests/
      Feature/Api/V1/
      Unit/
    composer.json

  ml-service/
    app/
    models/
    schemas/
    tests/
    requirements.txt

  e2e/
    fixtures/
    tests/

  docs/
    adr/
    api/
    data-dictionary/
    runbooks/

  PRD.md
  README.md
```

The `frontend/`, `backend/`, and `ml-service/` must be independently runnable. The SPA must not be rendered by Laravel.

### 7.2 Backend Separation of Concerns

- **Controllers:** authenticate/authorize, call one use case, return a Resource. No business rules, query construction, validation rules, or ad-hoc response shaping.
- **Form Requests:** own request authorization and backend validation.
- **Actions/Services:** own one business use case and transaction boundary.
- **Models:** own Eloquent relationships, casts, scopes, and narrow persistence behavior.
- **Policies:** own record-level authorization.
- **Resources:** own all successful API presentation.
- **Jobs:** own asynchronous, scheduled, or retryable execution.
- **Repositories:** introduced only for complex reusable queries or replaceable data sources; do not wrap simple Eloquent reads in empty abstractions.
- **DTOs/Contracts:** used at genuine boundaries such as the prediction service or document generator.

### 7.3 Frontend Separation of Concerns

- `frontend/src/app/` is reserved for the Next.js App Router — route segments, layouts, and `not-found`. It holds routing only.
- All React rendering components live under `frontend/src/features/components/`.
- Components do not perform raw `fetch` calls or parse backend responses.
- API clients live in `features/services`.
- Zod schemas live in `features/schemas`.
- Shared types live in `features/types`.
- Reusable browser behavior lives in `features/hooks`.
- Pure utilities live in `features/lib`.
- TanStack Query owns server state.
- Any component using state, effects, browser storage, or Next.js navigation hooks must declare `"use client"`.
- Use `@/` path aliases for internal imports.
- Use kebab-case filenames and folders.
- Export React components using PascalCase.
- Avoid `any`, duplicated API types, deep prop drilling, and unnecessary global state.

`/queue` is a dedicated kiosk route outside `/portal`; it does not render the
portal shell or portal authentication provider. It keeps the device session
separate from the Student session and uses the shared live Student queue panel.
The normal portal renders that live panel for Students but never renders a
claim control.

---

## 8. API Contract

### 8.1 General Rules

- Every application endpoint is versioned under `/api/v1`.
- Endpoint paths are noun-based and HTTP-semantic.
- Every success response uses a Laravel `JsonResource` or `ResourceCollection`.
- Controllers never return Eloquent models, paginators, collections, or ad-hoc arrays directly.
- Collection responses preserve pagination metadata and links.
- Return only fields required by the requesting client and role.
- Prevent N+1 queries and eager-load only Resource-required relationships.
- Use scoped route model binding where appropriate.
- Keep changes backward-compatible within a published API version.
- Use idempotency protection for payment confirmation, COM generation, and retryable critical mutations.

### 8.2 Success Envelope

A single resource:

```json
{
  "data": {
    "id": 123,
    "type": "enrollment",
    "status": "pending_payment"
  }
}
```

A collection:

```json
{
  "data": [],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 0
  }
}
```

### 8.3 Error Envelope

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The submitted data is invalid.",
    "errors": {
      "schedule_ids.0": [
        "The selected section is already full."
      ]
    },
    "request_id": "generated-correlation-id"
  }
}
```

Required status handling:

- `400` malformed request
- `401` unauthenticated or expired/revoked token
- `403` authenticated but unauthorized
- `404` resource not found
- `409` state or uniqueness conflict
- `422` validation failure
- `429` throttled, including `Retry-After`
- `5xx` server or dependency failure with no secret details

### 8.4 Initial Endpoint Groups

```text
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
GET    /api/v1/auth/me
POST   /api/v1/auth/account-setup
POST   /api/v1/auth/forgot-password
POST   /api/v1/auth/reset-password

GET    /api/v1/programs
GET    /api/v1/academic-terms
GET    /api/v1/subjects
GET    /api/v1/curricula
POST   /api/v1/curricula
PATCH  /api/v1/curricula/{curriculum}

GET    /api/v1/student-profile
GET    /api/v1/student-profiles
POST   /api/v1/student-profiles
GET    /api/v1/student-profiles/{studentProfile}
PATCH  /api/v1/student-profiles/{studentProfile}
POST   /api/v1/student-profiles/{studentProfile}/account-setup-invitations
GET    /api/v1/student-profile-change-requests
POST   /api/v1/student-profile-change-requests
PATCH  /api/v1/student-profile-change-requests/{studentProfileChangeRequest}
DELETE /api/v1/student-profile-change-requests/{studentProfileChangeRequest}
PATCH  /api/v1/student-profile-change-requests/{studentProfileChangeRequest}/decision

GET    /api/v1/faculty-availabilities
POST   /api/v1/faculty-availabilities
GET    /api/v1/sections
POST   /api/v1/sections
PATCH  /api/v1/sections/{section}
POST   /api/v1/schedule-proposals/{proposal}/approve
POST   /api/v1/schedule-proposals/{proposal}/return
POST   /api/v1/schedule-proposals/{proposal}/publish

GET    /api/v1/students/{student}/eligible-subjects
GET    /api/v1/enrollments
POST   /api/v1/enrollments
GET    /api/v1/enrollments/{enrollment}
POST   /api/v1/enrollments/{enrollment}/submit
POST   /api/v1/enrollments/{enrollment}/approve
POST   /api/v1/enrollments/{enrollment}/reject
POST   /api/v1/enrollments/{enrollment}/withdraw

GET    /api/v1/payment-queue
POST   /api/v1/enrollments/{enrollment}/confirm-payment
GET    /api/v1/enrollments/{enrollment}/matriculation-document
POST   /api/v1/queue-tickets
GET    /api/v1/queue-status
GET    /api/v1/queue-kiosk-credential
PUT    /api/v1/queue-kiosk-credential

GET    /api/v1/sections/{section}/roster
GET    /api/v1/sections/grade-submission
GET    /api/v1/sections/{section}/grades
POST   /api/v1/sections/{section}/grades
POST   /api/v1/sections/{section}/grades/submit

GET    /api/v1/analytics/section-demand
GET    /api/v1/analytics/attrition
GET    /api/v1/reports/honors
GET    /api/v1/ched-reports/{report}
GET    /api/v1/ched-reports/{report}/export
POST   /api/v1/reports/compliance-exports

GET    /api/v1/notifications
PATCH  /api/v1/notifications/{notification}/read
GET    /api/v1/audit-logs

PUT    /api/v1/super-admin/acting-context
DELETE /api/v1/super-admin/acting-context
GET    /api/v1/super-admin/users
POST   /api/v1/super-admin/users/invite
PATCH  /api/v1/super-admin/users/{user}/role
PATCH  /api/v1/super-admin/users/{user}/status
POST   /api/v1/super-admin/users/{user}/setup-invitation
POST   /api/v1/super-admin/users/{user}/password-reset
DELETE /api/v1/super-admin/users/{user}/sessions
DELETE /api/v1/super-admin/users/{user}
```

The final route inventory must be documented in an OpenAPI specification or equivalent API reference.

---

## 9. Authentication, CORS, and Security

### 9.1 Authentication

- Use Laravel Sanctum personal access tokens.
- The login use case calls a dedicated token-generation service.
- Return tokens only through `AuthResource`.
- Apply an approved expiration policy and store only hashed token values server-side.
- The normal portal's `auth-token` module is the only module that reads,
  stores, or removes the normal portal token from `localStorage`.
- The `/queue` kiosk uses a separate `kiosk-token` store for the persistent
  device token. A Student token used there is in-memory only; it is cleared on
  Done or refresh and never enters browser storage or a query key.
- The shared HTTP client appends `Authorization: Bearer <token>`.
- On `401`, clear and route only the session that made the request. Explicit
  kiosk or in-memory Student requests must not clear an unrelated portal
  session.
- Logout revokes the current token and clears local storage.
- Do not use session cookies, CSRF-cookie endpoints, or `withCredentials`.

`queue_kiosk` receives only the kiosk claim ability and is restricted to
`GET /api/v1/auth/me` and `POST /api/v1/auth/logout` when used directly. A
Student `POST /api/v1/queue-tickets` request must additionally send a valid
active kiosk token in `X-Queue-Kiosk-Token`; Accounting's on-behalf claim does
not use that header.

Public self-registration is out of scope. Admission Staff provisions student accounts through an authorized endpoint. If an account-creation endpoint returns a first-login token, it must follow the same `AuthResource` and token service controls.

The one unauthenticated read of queue data is `GET /api/v1/public/queue-view/{token}` (ADR 0044): the
live queue behind the link a freshman is emailed, scoped to one ticket by an unguessable token (only its
hash is stored), returning ticket numbers only and rate limited per link.

`super_admin` accounts are provisioned exclusively via `php artisan super-admin:provision` on the server, driven by `SUPER_ADMIN_EMAIL` — never through any web endpoint or default seeder. The provisioning command requires prior Google 2-Step Verification to be enabled on the owner's Google account before Google Sign-In is relied upon for access. See `docs/runbooks/super-admin.md`.

### 9.2 Browser Security

Because bearer tokens are stored in local storage:

- Enforce a strict Content Security Policy.
- Do not use `dangerouslySetInnerHTML` for untrusted content.
- Sanitize approved rich text.
- Avoid untrusted third-party scripts.
- Never include tokens in URLs, logs, analytics, screenshots, or error reports.
- Use secure response headers in production.
- Escape or safely render all user-controlled output.

### 9.3 CORS

- Use an explicit environment-based allowlist of frontend origins.
- Allow only required methods and headers.
- Disable credentials.
- Never use `*` in production.

### 9.4 Authorization

- Every resource endpoint has a Policy or explicit authorization decision.
- Validate both role-level and record-level access.
- Sensitive reads, exports, analytics, and overrides require explicit authorization.
- Grade access is constrained to assigned sections and authorized Registrar roles.
- Student records are scoped to the authenticated student unless a staff role is authorized.
- Super Admin acting context: when a `super_admin` token carries `acting_role`/`acting_college`
  columns, the `ApplySuperAdminActingContext` middleware overlays those values onto the in-memory
  `User` model so every Policy sees the acting role rather than `super_admin`. The raw database
  row is never modified; the real `super_admin` role remains on disk. The `EnsureUserIsSuperAdmin`
  middleware checks the raw value so the switch-endpoint itself remains accessible during acting.
  See ADR 0038.

### 9.5 Rate Limiting

- Strict login, reset, and account-provisioning limiters keyed by IP and normalized identifier.
- Authenticated API limits keyed by user ID or token.
- Anonymous limits keyed by IP.
- Return `429` and `Retry-After` consistently.
- Generic login errors must not reveal whether an account exists.

### 9.6 Data and Application Security

- Use Eloquent or parameter binding.
- Protect mass assignment.
- Validate upload size, MIME type, extension, and content where uploads are introduced.
- Use least-privilege database credentials.
- Encrypt approved sensitive fields at rest where warranted.
- Do not write secrets, tokens, full passwords, or unnecessary student data to logs.
- Use environment files for secrets and commit only safe examples.
- Apply secure backup, restoration, retention, and disposal procedures.
- Audit privileged reads and every privileged write.
- Review third-party packages, agent skills, and plugins as software supply-chain dependencies before installation.

The shared queue-kiosk password is an intentional reversible-secret exception:
the normal `users.password` value remains one-way hashed, while the canonical
device credential has separately encrypted ciphertext so an authorized
Accounting user can view it. Compromise of both the database and `APP_KEY`
can reveal that shared secret, so this mechanism must never be reused for a
personal-user password. Credential reads and rotations are authorized, audited,
private/no-store, and rotation revokes every active kiosk token.

### 9.7 Data Privacy Notice and Agreement (ADR 0055)

The system processes personal data under the Data Privacy Act of 2012 (RA 10173), including sensitive personal
information (age and education records). Therefore:

- **One versioned notice** states who it covers (applicants, students of every type, former students, faculty, staff,
  people who take a queue number), what is kept, why, who sees it, how it is shared, how it is protected, what is
  asked of the person, staff duties, and the person's rights. It is public (`/privacy`) and states no retention period
  or Data Protection Officer the school has not published.
- **Every person reads and agrees** to the current version before using the portal, at the first sign-in and again
  whenever the version changes. The agreement is recorded with its version and time and audited. The queue-kiosk
  device is not asked. The screen is not an authorization boundary.
- **Admission confirms** for each applicant that the notice was shown and agreed to before their details are saved;
  the confirmation is recorded against the new account, and the student still agrees themself.
- **Every form that takes personal details** shows a short notice of what it asks for, what it is used for and who
  can see it, and links to the whole notice. The words come from one source so the screens cannot disagree.
- New personal data may not be collected by a form unless the notice already covers its purpose; adding a purpose
  means changing the notice and its version.

---

## 10. Data Model

All names use Laravel/MySQL conventions. The final schema must be represented by reversible migrations and a synchronized data dictionary.

### 10.1 Identity and Organization

- `users`
  - `id`
  - `name`
  - `email` unique
  - `password`
  - `role`
  - `status`
  - `last_login_at`
  - timestamps

- `student_profiles`
  - `id`
  - `user_id` unique foreign key
  - `student_number` unique; assigned by the server as `YYYY-MM-NNNNN` from a per-year running counter, or entered by Admission for a Returnee or Existing Student who already has one (ADR 0042)
  - `program_id`
  - `curriculum_id`
  - `year_level`
  - `admission_status`
  - `academic_standing`
  - approved contact fields, including the details the CHED forms ask for: `gender`, `birth_date`, `citizenship`, `contact_number`, `street_barangay`, `town_city`, `province` (ADR 0049; typed by Admission when it creates the account, and the single `address` text is written from the street, town and province; Admission does not choose Regular or Irregular, the system decides it)
  - timestamps

- `student_number_sequences`
  - `year` primary key
  - `last_value` (last 5-digit suffix handed out that year)
  - timestamps

- `programs`
  - `id`
  - `code` unique
  - `name`
  - `status`
  - timestamps

- `academic_terms`
  - `id`
  - `school_year`
  - `semester`
  - `starts_at`
  - `ends_at`
  - `enrollment_opens_at`
  - `enrollment_closes_at`
  - `status`
  - timestamps

### 10.2 Curriculum and Scheduling

- `subjects`
  - `id`
  - `code` unique
  - `title`
  - `units`
  - `status`
  - timestamps

- `curricula`
  - `id`
  - `program_id`
  - `name`
  - `effective_school_year`
  - `status`
  - timestamps

- `curriculum_subjects`
  - `id`
  - `curriculum_id`
  - `subject_id`
  - `year_level`
  - `semester`
  - `is_required`
  - unique curriculum/subject placement as approved

- `subject_prerequisites`
  - `id`
  - `curriculum_subject_id`
  - `prerequisite_subject_id`
  - `minimum_grade`
  - unique mapping and cycle prevention

- `faculty_availabilities`
  - `id`
  - `professor_id`
  - `academic_term_id`
  - day/time fields
  - timestamps

- `faculty_subject_preferences`
  - `id`
  - `professor_id`
  - `academic_term_id`
  - `subject_id`
  - `rank`
  - timestamps

- `sections`
  - `id`
  - `academic_term_id`
  - `subject_id`
  - `section_code`
  - `professor_id` nullable
  - schedule and room fields
  - `capacity`
  - `viability_threshold`
  - `enrolled_count`
  - `status`
  - timestamps

- `schedule_proposals`
  - `id`
  - `academic_term_id`
  - `submitted_by`
  - `status`
  - decision fields
  - timestamps

### 10.3 Academic and Enrollment Records

- `academic_grades`
  - `id`
  - `student_id`
  - `subject_id`
  - `section_id` nullable for transferred records
  - `academic_term_id`
  - `final_grade`
  - `remarks`
  - `status`
  - `encoded_by`
  - `submitted_at`
  - `locked_at`
  - timestamps

- `enrollments`
  - `id`
  - `student_id`
  - `academic_term_id`
  - `status`
  - `total_units`
  - `submitted_at`
  - `registrar_decided_at`
  - `payment_confirmed_at`
  - `enrolled_at`
  - timestamps
  - unique active enrollment constraint per student and term

- `enrollment_subjects`
  - `id`
  - `enrollment_id`
  - `section_id`
  - `status`
  - timestamps
  - unique enrollment/section mapping

- `queue_tickets`
  - `id`
  - `enrollment_id` unique
  - `ticket_number` unique
  - `queue_date`
  - `status`
  - `served_at`
  - timestamps

- `payments`
  - `id`
  - `enrollment_id` unique
  - `confirmed_by`
  - `external_reference` nullable
  - `amount` nullable unless approved as required
  - `confirmed_at`
  - timestamps

- `enrollment_documents`
  - `id`
  - `enrollment_id` foreign key
  - `document_type` (at minimum `com`; add `cor` only if GRC confirms it is a distinct artifact)
  - `document_number` unique per approved document type
  - `storage_path` or generated representation
  - `content_hash`
  - `generated_at`
  - timestamps
  - unique enrollment/document-type mapping

- `transferee_credits`
  - `id`
  - `student_id`
  - source-institution fields approved by GRC
  - mapped subject and grade fields
  - `processed_by`
  - timestamps

- `withdrawal_requests`
  - `id`
  - `enrollment_id`
  - `reason`
  - `status`
  - `processed_by`
  - `processed_at`
  - timestamps

### 10.4 Analytics, Notifications, and Audit

- `prediction_runs`
  - `id`
  - `type`
  - `academic_term_id` nullable
  - `model_version`
  - `feature_schema_version`
  - `status`
  - metric and error-summary fields
  - `started_at`
  - `completed_at`
  - timestamps

- `section_demand_forecasts`
  - `id`
  - `prediction_run_id`
  - `academic_term_id`
  - `subject_id`
  - predicted demand and suggested section count
  - confidence or uncertainty fields
  - timestamps

- `attrition_predictions`
  - `id`
  - `prediction_run_id`
  - `student_id`
  - risk probability
  - risk band
  - approved explanation fields
  - timestamps

- `notifications`
  - `id`
  - `user_id`
  - `type`
  - `message`
  - `read_at`
  - timestamps

- `audit_logs`
  - `id`
  - `actor_user_id`
  - `acting_role` nullable — the institutional role the Super Admin was acting as when the
    event occurred; null for all non-Super-Admin actors and for Super Admins in Console mode
    (added by migration `2026_10_01_000002`)
  - `acting_college` nullable — the college scope of the acting role, if applicable; null
    when the acting role has no college dimension (added by migration `2026_10_01_000002`)
  - `action`
  - `auditable_type`
  - `auditable_id`
  - before/after summary
  - `reason` nullable unless required
  - request and IP context approved by policy
  - timestamps

- `report_exports`
  - `id`
  - `requested_by`
  - `type`
  - `academic_term_id`
  - `status`
  - `storage_path`
  - `generated_at`
  - timestamps

### 10.5 Manuscript ERD Alignment

The manuscript ERD explicitly identifies the core entities `System_Users`, `Student_Profile`, `Faculty_Schedules`, `Curriculum_Rules`, `Subject_Prerequisites`, `Pending_PEF_Subjects`, `Enrollment_Transactions`, `Academic_Grades`, `Predictive_Analytics_Logs`, and `Compliance_Logs`. The normalized implementation schema in this PRD may split or rename these into Laravel-conventional tables, but the Data Dictionary must maintain a one-to-one traceability map from every manuscript entity and attribute to its implementation table and field.

The implementation must preserve the manuscript's intent that:

- identity and portal access are centralized;
- student profiles and grades support prerequisite validation;
- curriculum, prerequisite, faculty, and section data support schedule generation;
- pending PEF and enrollment transactions support the queue and financial bridge;
- analytical logs are isolated from daily transaction processing;
- compliance/audit records support privileged actions and mandatory reporting.

### 10.6 Database Rules

- Normalize to third normal form by default.
- Use foreign keys and explicit delete behavior.
- Add database unique constraints for business uniqueness.
- Add indexes for foreign keys and measured query patterns.
- Do not add speculative indexes.
- Validate critical queries with `EXPLAIN ANALYZE`.
- Avoid `SELECT *`, N+1 loading, and unbounded lists.
- Use cursor pagination for very large mutable lists where appropriate.
- Use transactions for every multi-write use case.
- Test migrations and constraints against MySQL, not only an in-memory substitute.

---

## 11. Predictive Analytics Specifications

### 11.1 Supported Models

The manuscript requires supervised machine learning and statistical validation but does not permanently lock production to one algorithm. The following are initial implementation candidates and may be replaced only when comparative validation, stakeholder review, versioning, and rollback requirements are satisfied.

- Random Forest regression or an approved equivalent for section demand.
- XGBoost classification or an approved equivalent for attrition risk.
- Honors evaluation remains deterministic.
- Government compliance reports remain deterministic.
- Subject eligibility remains deterministic; recommendation ranking may use approved historical signals but cannot override eligibility rules.

### 11.2 Data Flow

1. Laravel queries the minimum required features.
2. Laravel sends a versioned JSON request to the private prediction service.
3. The service validates the schema and loads the approved model artifact.
4. The service returns a versioned JSON result.
5. Laravel validates and stores the result.
6. Dashboards read cached database results rather than invoking the model during page rendering.
7. Scheduled jobs refresh results at an approved cadence.

### 11.3 Model Governance

- Record training data period and source.
- Remove direct identifiers from training data unless necessary and approved.
- Document features, target variable, exclusions, limitations, and intended use.
- Evaluate data leakage, class imbalance, calibration, and subgroup performance.
- Version model artifacts and input schemas.
- Retain reproducible training code and evaluation results.
- Require authorized review before a model becomes active.
- Provide rollback to the previous approved model.
- Monitor drift and forecast error per term.
- Never treat risk as proof of future behavior.
- Use predictions to prioritize support and planning, not punishment.

### 11.4 Reliability

- Use timeouts, retries with limits, and circuit-breaking behavior for the prediction service.
- Do not block normal page rendering while a scheduled prediction runs.
- Cache the last successful result.
- Show a clear stale-data indicator.
- Log dependency failures without exposing student data.
- Test missing-model, malformed-response, timeout, and service-unavailable paths.

---

## 12. Frontend Design and User Experience

### 12.1 Design System

Use Tailwind CSS semantic tokens and shadcn/ui composition.

GRC brand tokens:

```css
:root {
  --grc-primary: #c8102e;
  --grc-background: #ffffff;
  --grc-neutral: #6b7280;
  --grc-accent: #d4af37;
}
```

Map these values to the shadcn theme instead of scattering raw color utilities.

- Primary red: important actions and active navigation.
- White: main background and readable content surfaces.
- Neutral gray: secondary text, borders, and disabled states.
- Gold: restrained accent only; verify text contrast before use.
- Destructive actions must not be visually confused with the normal primary action.
- Status must not rely on color alone.

### 12.2 Required shadcn/ui Patterns

Use a component only when it fits the interaction.

- `Field` composition for forms.
- `Alert` for persistent inline feedback.
- `sonner` for transient confirmation.
- `Skeleton` or `Spinner` for loading.
- `Empty` for empty states.
- `AlertDialog` for destructive confirmation.
- `Dialog`, `Sheet`, or `Drawer` according to viewport and task.
- `Table` and `Pagination` for tabular lists.
- `Badge` for status with text labels.
- `Tabs` only where content is genuinely parallel, not as hidden navigation.
- Accessible menus, comboboxes, date pickers, and tooltips when needed.

### 12.3 Form Behavior

- Backend Form Requests are authoritative.
- Mirror rules in Zod for user experience.
- Use React Hook Form.
- Map `422` errors to named fields.
- Preserve valid input.
- Set `aria-invalid`.
- Place messages near the affected field.
- Show a non-field message for conflicts and server failures.
- Prevent accidental duplicate submission while allowing safe retry.

### 12.4 Required Application States

Every page or feature must define:

- loading
- empty
- success
- validation error
- authorization error
- not found
- conflict
- throttled
- dependency failure
- offline or connection interruption where applicable

### 12.5 Accessibility and Responsiveness

- Meet WCAG 2.1 AA as the minimum target.
- Use semantic HTML and explicit labels.
- Support keyboard-only operation and visible focus.
- Provide meaningful headings and landmarks.
- Announce dynamic errors and status updates.
- Do not use icons without accessible names.
- Use responsive layouts for mobile, tablet, and desktop.
- Keep tables usable with responsive overflow or alternate cards.
- Test zoom and text resizing.
- Avoid motion that cannot be reduced.

### 12.6 Portal Content

Each role receives a role-correct dashboard containing only authorized modules. Shared portal elements include:

- logged-in name and role
- logout
- notification center
- profile and password settings
- help/report-an-issue entry
- current academic term context
- accessible breadcrumb or page context
- last-updated indicators for live and analytical data

Student portal views include the reusable live queue panel, which shows only
the Student's own ticket and privacy-preserving board information (ticket
numbers, not other Students' identities). It polls every three seconds and
refetches on focus; browsers or operating systems can still throttle a
background tab, so the UI must ask the Student to keep it open and visible near
their service time. Claims remain available only on `/queue`.

---

## 13. Development-Time Agent Skills and Plugins

These capabilities guide implementation work. They are not shipped to users, are not application runtime dependencies, and do not create product requirements.

### 13.1 Required/Approved Capabilities

| Capability | Type | Required use |
| --- | --- | --- |
| **Superpowers** | Agent plugin / skills methodology | Use for structured discovery, implementation planning, test-driven development, systematic debugging, code review, and branch-completion workflows. |
| **Frontend Design** (`frontend-design`) | Agent skill | Use when designing or refining pages, components, responsive layouts, and visual systems. It must follow this PRD's brand, accessibility, and shadcn/ui rules. |
| **GSD** (Get Shit Done) | Spec-driven workflow system / Codex skills | Use for project initialization, codebase mapping, phased planning, execution tracking, verification, and preserving context in project planning files. |
| **Context7** | MCP documentation plugin | Use to retrieve current, version-specific official documentation and examples for Laravel, Sanctum, React, TypeScript, Vite, Tailwind CSS, shadcn/ui, TanStack Query, Playwright, MySQL, and ML libraries. |

### 13.2 Usage Order

For a significant feature:

1. Read this PRD and identify the user journey, role, data, security, and acceptance criteria.
2. Use GSD or Superpowers to create a focused plan and test strategy.
3. Use Context7 before relying on version-sensitive framework or library APIs.
4. Use Frontend Design for UI implementation or substantial UI refinement.
5. Implement the smallest complete vertical slice.
6. Run targeted tests.
7. Perform security, accessibility, design, and code review.
8. Run the full applicable quality suite before handoff.
9. Record changed files, migrations, assumptions, tests run, and intentionally unrun checks.

### 13.3 Guardrails

- Skills and plugins may not override this PRD.
- Do not use plugin output as authorization or as a source of unstated institutional requirements.
- Do not send secrets, access tokens, production credentials, or unrelated repository content to a plugin.
- Do not send identifiable student records or production datasets to external documentation or agent services.
- Review the source, permissions, license, update behavior, and supply-chain risk before installing a third-party capability.
- Pin or record the approved tool version when reproducibility matters.
- Prefer official documentation and primary sources.
- Context7 content must be checked against the project's installed version and tests.
- GSD and Superpowers planning artifacts must not duplicate or contradict each other; choose one primary phase tracker for a given implementation cycle.
- Frontend Design cannot replace usability testing, accessibility testing, or stakeholder approval.

### 13.4 Additional Skills from the Project Instructions

Use when relevant:

- `shadcn` for inspecting and managing shadcn/ui components.
- `web-design-guidelines` for completed UI/UX and accessibility review.
- browser-control capability for interactive inspection; Playwright remains the required automated end-to-end framework.
- `openai-docs` for current official Codex configuration guidance.
- `find-skills` only when the project needs a workflow not already covered.

### 13.5 Optional Workspace Plugins

Connect only when the source is explicitly in scope:

- GitHub for issues, pull requests, reviews, and repository coordination.
- Figma for approved designs and component specifications.
- Notion for an approved PRD or decision log.
- Atlassian Rovo for Jira and Confluence requirements.
- Slack or Teams for explicitly relevant, approved engineering decisions.

---

## 14. Testing and Quality Gates

Every user-facing or behavior-changing task includes tests.

### 14.1 Backend

Test:

- Form Request validation and authorization
- Policies
- Actions/Services
- transactions and rollback
- API Resources and exact response shapes
- pagination metadata
- bearer authentication
- token revocation and expiration
- rate limits
- state transitions
- idempotency
- audit logging
- prediction-service failure handling
- document generation
- MySQL constraints

### 14.2 Frontend

Test:

- Zod schemas
- Form Request error mapping
- authorization-aware UI
- protected routes
- token interceptor behavior
- loading, empty, error, conflict, and retry states
- keyboard behavior for complex controls
- critical tables and filters
- status rendering
- no direct `localStorage` access outside the token module

### 14.3 End-to-End

Playwright runs against the SPA, API, and isolated MySQL database.

Critical journeys:

1. Authorized account sign-in and token persistence.
2. Protected-route redirect without a token.
3. Faculty availability submission.
4. Program Chair section creation and proposal submission.
5. Dean and Executive Director schedule approval.
6. Student eligibility, selection, and enrollment submission.
7. Registrar approval.
8. Accounting confirmation and COM availability.
9. Professor grade submission.
10. Unauthorized cross-role access denial.
11. Validation errors rendered in the correct fields.
12. Throttle behavior.
13. Withdrawal idempotency.
14. Prediction-service failure with cached fallback.
15. Compliance report authorization.

Rules:

- Use deterministic factories and state reset.
- Use semantic locators first.
- Do not use arbitrary sleeps.
- Capture traces and screenshots on failure.
- Never use production credentials or production student data.

### 14.4 Security Verification

- SQL injection attempts.
- Stored and reflected XSS attempts.
- authorization bypass and insecure direct object reference attempts.
- mass-assignment attempts.
- token leakage checks.
- revoked and expired token tests.
- CORS allowlist tests.
- rate-limit tests.
- malicious file upload tests if uploads exist.
- dependency and secret scanning.
- privileged audit-log coverage.

### 14.5 Performance Verification

Define target values during architecture validation and pilot baselining.

At minimum test:

- eligible-subject query
- schedule and section lists
- Registrar approval queue
- payment queue
- dashboards
- report exports
- bulk grade submission
- prediction refresh
- concurrent final enrollment transactions

Use production-like data volume and inspect critical MySQL queries with `EXPLAIN ANALYZE`.

### 14.6 Completion Gate

Before handoff, run the applicable full suite:

- Laravel Pint
- PHPStan/Larastan
- Pest or PHPUnit
- ESLint
- Prettier check
- TypeScript check
- Vitest
- production frontend build
- prediction-service tests
- Playwright
- migration fresh/rollback checks
- security scans

The change summary must report what ran and why any check was intentionally not run.

---

## 15. ISO/IEC 25010 Non-Functional Requirements

### 15.1 Functional Suitability

- Prerequisite, unit, conflict, capacity, approval, payment, and document rules produce correct results.
- Features match role responsibilities and approved workflows.
- Automated tests cover critical business rules.

### 15.2 Performance Efficiency

- Common interactions remain responsive at expected enrollment load.
- Long-running reports and predictions execute asynchronously.
- Database queries are indexed based on measured patterns.
- The system prevents duplicate work under retries and concurrency.

### 15.3 Compatibility

- The SPA and API communicate through a documented versioned contract.
- Supported browsers are documented.
- Production-stable dependency compatibility is verified before upgrades.

### 15.4 Usability

- Role dashboards use consistent navigation and terminology.
- Forms explain errors and preserve valid input.
- Status and next action are clear.
- Mobile, tablet, desktop, keyboard, and assistive-technology use are supported.

### 15.5 Reliability

- Critical writes are transactional.
- External-service failures degrade gracefully.
- Backups and restoration are tested.
- Scheduled jobs are observable and retryable.
- Duplicate requests do not corrupt data.

### 15.6 Security

- Bearer authentication, Policies, validation, rate limits, CSP, CORS, secure headers, least privilege, protected secrets, safe logging, and audit trails are enforced.
- Sensitive student and analytics data is disclosed only to authorized users.

### 15.7 Maintainability

- SOLID and separation of concerns are followed.
- Modules are cohesive and explicitly named.
- Static analysis and formatting are enforced.
- Migrations, APIs, data dictionary, tests, and runbooks remain synchronized.
- Material decisions are captured in ADRs.

### 15.8 Portability

- Frontend, backend, and prediction service have documented setup and environment examples.
- Deployment does not depend on a developer's machine.
- Production data migration and rollback procedures are documented.

### 15.9 Manuscript Evaluation Protocol

The formal capstone evaluation must preserve the manuscript's descriptive-evaluation design:

- **Respondent groups:** IT experts; and authorized end users consisting of the Registrar Head, Program Chair, Dean, Admission Staff, and selected students.
- **Instrument:** a structured questionnaire adapted from ISO/IEC 25010 and covering Functional Suitability, Reliability, Performance Efficiency, Usability, Security, Compatibility, Maintainability, and Portability.
- **Analysis:** compute the Weighted Mean for each criterion and the overall result.
- **Interpretation:** use the manuscript's exact five-point scale: `4.21–5.00 Highly Acceptable`, `3.41–4.20 Acceptable`, `2.61–3.40 Neutral`, `1.81–2.60 Unacceptable`, and `1.00–1.80 Totally Unacceptable`.
- **Predictive validation:** evaluate section-demand forecasting separately using approved statistical error metrics and report the dataset split, baseline, model version, and actual metric values.

---

## 16. Development Roadmap

Delivery follows the manuscript's Agile Methodology. Requirements are prioritized in a Product Backlog, implemented through time-boxed Sprints, designed/built/tested within each increment, and reviewed in a Sprint Retrospective with relevant GRC stakeholders such as the Registrar Head and Program Chair. Testing results and approved retrospective decisions feed back into planning for the next sprint.

The roadmap maps to the manuscript's six structural phases: Project Planning and requirements gathering, System Design, Development, Testing and software quality assurance, Deployment, and Stakeholder Review/retrospectives. Each phase below must deliver a working, tested vertical slice. A phase is incomplete until authorization, validation, API Resources, frontend states, responsive/accessibility behavior, tests, documentation, and the applicable sprint review are complete.

### Phase 0 — Discovery, Policy Confirmation, and Foundations

**Objectives**

- Confirm policy placeholders with authorized GRC offices.
- Record exact stable versions and compatibility.
- Establish repositories, environments, CI, architecture, API conventions, and base migrations.

**Deliverables**

- `README.md`
- safe `.env.example` files
- version compatibility record
- ADRs for authentication, prediction-service bridge, document generation, and storage
- frontend/backend/ml-service scaffolding
- shared error contract
- initial OpenAPI document
- MySQL connection and reversible base migrations
- deterministic role seeders
- CI quality checks

**Definition of Done**

- All three services run independently.
- Fresh migrations and rollback work against MySQL.
- CI runs formatting, static analysis, tests, and frontend build.
- No real secret is committed.
- One seeded account per role can be created safely.

### Phase 1 — Authentication and RBAC Shell

**Objectives**

- Implement bearer authentication, role policies, protected routes, role-correct navigation, profile, logout, and rate limiting.

**Definition of Done**

- All nine roles can sign in and receive only authorized navigation.
- Cross-role direct API access returns `403`.
- Missing, expired, and revoked tokens return `401`.
- Logout revokes the current token.
- No component accesses local storage outside `auth-token`.
- Login and protected-route E2E tests pass.

### Phase 2 — Curriculum, Faculty Availability, and Schedule Approval

**Objectives**

- Deliver Process 1.0 end to end.

**Definition of Done**

- Program Chair manages curriculum and prerequisites.
- Cycle detection works.
- Professor submits availability and preferences.
- Program Chair creates a proposal with forecast context.
- Dean and Executive Director approval order is enforced.
- Publishing notifies faculty and exposes authorized schedules.
- Sections below the confirmed viability threshold are blocked from publication unless the approved exception authority completes an audited override.

### Phase 3 — Student Enrollment and Digital Advising

**Objectives**

- Deliver Process 2.0 end to end.

**Definition of Done**

- Admission Staff provisions a student.
- Eligible subjects respect academic history and curriculum.
- Conflict, capacity, duplicate, unit, prerequisite, and regular-block eligibility rules work server-side.
- Student submits one atomic enrollment.
- One queue ticket can be claimed after Registrar approval, and the Digital
  PEF summary is produced.
- Validation errors map correctly to the SPA.
- Concurrency tests prevent overbooking and duplicate seat reservation.

### Phase 4 — Registrar, Accounting, Withdrawal, and COM

**Objectives**

- Deliver Process 3.0 end to end.

**Definition of Done**

- Registrar approval activates payment.
- Accounting sees only eligible pending-payment records.
- Payment confirmation is idempotent.
- Enrollment finalization and COM generation complete safely.
- The student can view and print/download the COM. Any separate COR behavior is implemented only after GRC resolves the terminology and document-policy decision.
- Transferee-credit and withdrawal workflows are authorized and audited.
- Repeated withdrawal or confirmation does not duplicate side effects.

### Phase 5 — Grades, Analytics, Honors, and Compliance

**Objectives**

- Deliver grade workflows and Process 4.0.

**Definition of Done**

- Professors submit grades only for assigned sections.
- Demand forecast feeds Program Chair planning.
- Attrition analytics are role-restricted and advisory.
- Attrition is factual and aggregate-only: a non-demo student officially
  enrolled in an AY first semester but not officially enrolled in its second
  semester. It returns no student identities and does not use risk outputs.
- Honors results match deterministic policy tests.
- Compliance exports are authorized and audited.
- Prediction failure displays cached data and a freshness note.
- Model version and evaluation metrics are stored.

### Phase 6 — Cross-Cutting Product Polish

**Objectives**

- Complete notifications, profile settings, help, responsive design, accessibility, consistent statuses, and operational observability.

**Definition of Done**

- All role portals use one coherent design system.
- Keyboard and screen-reader spot checks have no critical blockers.
- Every page has defined loading, empty, and error states.
- Structured logs and job monitoring are available.
- Notification behavior is tested.

### Phase 7 — Verification and ISO/IEC 25010 Evaluation

**Objectives**

- Complete unit, feature, integration, E2E, security, performance, predictive-model, and UAT evaluation.

**Definition of Done**

- The full quality suite passes or approved exceptions are documented.
- Security testing finds no unresolved critical or high-severity defect.
- Model evaluation results are recorded.
- UAT uses the approved Likert-scale and ISO/IEC 25010 rubric.
- Every failing item has an owner and tracked resolution.

### Phase 8 — Deployment and Handoff

**Objectives**

- Deploy to the approved GRC environment and transfer maintainable operations.

**Deliverables**

- production configuration and secret-management procedure
- HTTPS and secure-header configuration
- MySQL migration and backup/restore runbook
- frontend deployment procedure
- API worker, queue, scheduler, and prediction-service process configuration
- monitoring and incident response notes
- user/account provisioning runbook
- curriculum and academic-term maintenance runbook
- data dictionary
- API documentation
- test and UAT report
- model card and evaluation report

**Definition of Done**

- GRC staff complete one full enrollment cycle in production-like conditions without developer intervention.
- All nine roles pass smoke tests.
- Backups restore successfully.
- Production does not contain test credentials or seed data.
- Operations staff can follow the handoff runbooks.

---

## 17. Open Decisions Requiring GRC Approval

Do not hardcode these values until confirmed:

- Official passing-grade rule, including special marks and equivalent grades.
- Maximum regular units and overload approval workflow.
- Section-viability threshold and exception authority.
- Room capacity source and conflict rules.
- Enrollment reservation timeout and seat-release rules.
- Queue-ticket reset, priority, active serving-number policy, and the exact Accounting Staff authority for recording Payment Confirmation.
- Registrar approval requirements for regular and irregular students.
- Payment confirmation fields and supporting reference requirements.
- Refund or clawback of the full-payment discount when a fully paid student withdraws or is voided: **decided by the owner on 2026-10-12.** Voiding or withdrawing ends the enrollment process only; the payment is not refunded and the discount stays on the paid assessment, so a student who was voided after paying does not go back to the Cashier. GRC has no refund process (ADR 0046).
- Whether Certificate of Registration (COR) and Certificate of Matriculation (COM) are distinct artifacts or synonymous terms; if distinct, confirm each audience, trigger, fields, numbering, signatures, and retention.
- Official COM format, document numbering, signatures, and retention.
- Honors cutoff, disqualifying grades, and tie handling.
- Government report fields, file format, naming, and sign-off.
- Attrition intervention workflow and authorized viewers.
- Prediction refresh cadence.
- Token lifetime and session-equivalent user experience.
- Password and account recovery policy.
- Data retention, archive, backup, and disposal schedules.
- Hosting environment and supported browsers.

Each approved decision must update this PRD, configuration, tests, and the data dictionary where applicable.

---

## 18. Delivery Discipline

- Read the affected PRD section before changing code.
- Identify the user journey, role, acceptance criteria, data, authorization, and non-functional requirements.
- Make focused changes only.
- Do not upgrade unrelated dependencies.
- Keep environment examples current and secret-free.
- Document setup, migration, seeding, test, and run commands.
- Provide migration and rollback notes for schema changes.
- Use clear user-facing errors and structured logs without secrets.
- Treat a feature as complete only when backend, frontend, authorization, validation, tests, accessibility, responsiveness, and documentation are complete.
- Report changed files, assumptions, tests run, and intentionally unrun checks.
- Update this PRD when approved product behavior, schema, API contract, model behavior, or policy values change.

---

## 19. Product Completion Criteria

The product is ready for institutional handoff when:

- All approved scope is implemented.
- The full schedule-to-enrollment-to-payment-to-COM journey passes, with COR included only if GRC confirms it is a separate required artifact.
- All nine roles have verified authorization boundaries.
- Prerequisite, unit, conflict, capacity, and state-transition rules pass.
- Analytics are accurate enough for the approved pilot threshold and clearly advisory.
- Accessibility, security, reliability, and performance gates pass.
- UAT and ISO/IEC 25010 evaluation are completed.
- Data migration, backup, restoration, deployment, and runbooks are verified.
- No unresolved critical defect remains.
- GRC stakeholders approve the release.

---

*This PRD v3.1 defines the product and implementation constraints for the Automated Enrollment System with Predictive Analytics. Development-time agent skills and plugins support the engineering workflow but do not replace institutional approval, authorization controls, testing, or the requirements in this document.*

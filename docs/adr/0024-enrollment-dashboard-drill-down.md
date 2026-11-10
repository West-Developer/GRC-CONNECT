# ADR 0024 — Enrollment Dashboard Drill-Down: Student Groups, Department/Section/Student Levels, and a Narrow Amendment to ADR 0017

**Status:** Accepted
**Date:** 2026-09-24
**Amends:** ADR 0017 (aggregate-only dashboards for Dean and Executive Director), for this drill-down only.

## Context

Stakeholder feedback (Google Doc "DerickSystemImprovement", 2026-09-24) asked
for the Registrar Head's Enrollment Dashboard to show, in one view, how many
students are enrolled, not enrolled, not yet done and in progress, and to let
the reader click from the overall picture down through **department → section →
student → the student's information**. The same features were requested for the
Dean and the Executive Director. It also asked to drop the enrollment funnel
(hard to read) and to show the *Enrolled* step in the in-progress chart.

ADR 0017 made Dean and Executive Director aggregate-only on purpose: no
student identity crosses the boundary, because PRD §3.6 keeps the Executive
Director away from detailed student records and §9.4 guards it. The requested
student level contradicts that for those two roles. The product owner chose,
explicitly, to allow a student-level read-only view for Dean and Executive
Director rather than stop at sections.

## Decisions

**1. Four groups, derived only from `EnrollmentStatus`.** `EnrollmentStatusGroup`:
`enrolled` = `enrolled`; `in_progress` = `draft` + `pending_registrar_approval`
+ `pending_payment`; `not_enrolled` = `rejected` + `cancelled` + `withdrawn`;
`not_yet_done` = an eligible student with no enrollment row this term. This
respects ADR 0017's rule that grouping is driven by the PRD-authoritative
enums, never by provisional vocabularies.

**2. Counts are per student, not per enrollment row.** A student's *current*
enrollment for the term is a non-terminal one if any exists, otherwise the
latest terminal one. A student who was rejected twice and then enrolled is one
"Enrolled", not two rejections plus an enrollment. The existing
`enrollment-summary` endpoint keeps counting rows and is unchanged (including
`funnel_counts`, which the UI simply stops rendering).

**3. Who is counted.** Every student with a current enrollment, plus every
*eligible* student without one. Eligible = an active account, no graduation
recorded, admission status not `graduated` or `withdrawn`, and not a demo
account (the same demo exclusion the Honors and Attrition reports use).
`AdmissionStatus` is a provisional vocabulary (see its docblock); when GRC
confirms the real values this filter must be revisited. It is defined in one
place, `EnrollmentStatusPopulation`, so overview, sections, list and detail can
never disagree.

**4. A student is in exactly one section.** The section code that covers most
of the current enrollment's non-dropped subjects, ties broken by section code.
Regular students take cross-college subjects (LEAD, PATHFIT) in other section
codes, so "every section a student touches" would double count and section
totals would not add up to the department total. Students with no enrollment
subjects sit in a "No section yet" row (`section_code = null`). Department =
the student's program college.

**5. Endpoints.** Under `role:dean,executive_director,registrar_head,program_chair`:
`GET /dashboards/enrollment-status` (overview + per-department),
`.../sections?department=` (per section), `.../students?department=` with
optional `section_code` | `without_section`, `group`, `page`, `per_page` (max
50), and `.../students/{studentProfile}` (one student). The first two are
aggregate-only and use the existing `view-enrollment-summary` gate. The last two
use a new `view-enrollment-status-students` gate so the exception can be
narrowed or revoked on its own.

**6. The student level is a narrow, audited exception.**
- **Fields:** student number, name, program, department, year level, section,
  enrollment status and its timestamps, and (detail only) the non-dropped
  subjects with units and section. Never contact data, grades, payment amounts
  or balances.
- **Read-only.** No write endpoint is added.
- **Scope:** Registrar Head and Executive Director are institution-wide. Dean and
  Program Chair are limited to their own college. A Program Chair with no
  college is unscoped (matching `Section::scopeVisibleTo`); a Dean with no
  college is refused rather than shown everything. A student outside the
  actor's college is reported as 404, so the endpoint can't be used to probe
  other colleges.
- **Audit:** opening a student list writes `enrollment_status_dashboard.student_list_viewed`
  (first page only, so paging one list is one row); opening a student writes
  `enrollment_status_dashboard.student_viewed`. Payloads carry filters and
  counts, never identities.

## Alternatives considered

- **Stop at the section level for Dean/Executive Director.** Keeps ADR 0017
  intact but gives those roles fewer features than the Registrar Head. Rejected
  by the product owner.
- **Widen `Enrollment::scopeVisibleTo` / `EnrollmentPolicy` to Dean and Executive
  Director.** Would hand them the full enrollment record for every student, the
  exact thing ADR 0017 avoided. Rejected in favour of a purpose-built endpoint
  with a fixed field set and an audit trail.
- **Group students by every section code they touch.** Simpler SQL, but section
  counts stop adding up (see decision 4).

## Consequences

- ADR 0017's "no student identity crosses the boundary" now has one documented
  exception for this drill-down. `stuck-enrollments` and the institution summary
  are unchanged.
- The Enrollment Dashboard's "Enrollment status" pill chips and the funnel are
  replaced by the four-group overview; the funnel component is no longer used
  by the dashboard.
- The eligibility filter (decision 3) is provisional and flagged in code.
- `EnrollmentStatusDashboardTest` covers counts, scoping, the section partition,
  filters, validation, audit and the narrow field set.

## Amendment 2026-09-26 (stakeholder Doc 14, S16)

- **Audience.** The dashboard opens to Registrar Staff, Accounting Staff, and Admission Staff as well (new ability `view-enrollment-status`; the drill-down student levels follow it). Professors and students still have no access. The institution-level Enrollment Summary (section fill, grade submission) stays with the roles that see every stage.
- **Each new role sees only the students waiting on it, enforced on every level.** `EnrollmentStatusPopulation::query($term, $scope, $actor)` applies the stage: Registrar Staff only `pending_registrar_approval`; Accounting Staff only `pending_payment`; Admission Staff only students whose `admission_status` is `pending` or `admitted`. The overview, section breakdown, student list, and student detail all go through it, and a stage-limited role that opens a student outside its stage gets "not found". Dean, Executive Director, Registrar Head, and Program Head keep every stage (Dean and Program Head still limited to their own college). The Admission rule leans on the provisional `AdmissionStatus` vocabulary and needs revisiting when GRC confirms it (a continuing student whose status was never advanced to `enrolled` would show up there).
- **"In progress" is now "Ongoing"** in the group label (server `EnrollmentStatusGroup::label()` and the frontend group presentation). The group key `in_progress` is unchanged.
- **Stuck Students is retired from the UI.** The Dean's "Stuck Students" module and the "past threshold" split in the step chart are gone; the dashboard no longer calls `GET /stuck-enrollments`. The endpoint, its policy, and the policy-settings entry for the dwell threshold are left in place, unused, until the owner confirms removing them (PRD §3.5 and FR-ANL-003 still name stuck-student reports).
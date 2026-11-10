# ADR 0034 — Enrollment Movements (Drops, Withdrawals) and Recorded Course Shifts

**Status:** Accepted
**Date:** 2026-09-26
**Amends:** PRD §3.7 (Registrar Head analytics) and §3.4 (Program Head analytics). Follows ADR 0017 (analytics stay aggregate-only).

## Context

Stakeholder Doc 14: Enrollment Analytics should have one navigation with the ways students leave a subject or a course of study: drops, withdrawals, and shifting from one course to another ("from course, to course"). The owner confirmed on 2026-09-26 that a course shift is a Registrar-recorded record, not a student request workflow.

Before this change (verified in code): analytics counted enrollments, retention, and attrition; approved drops (`enrollment_change_requests`, type `drop`) and approved withdrawals (`withdrawal_requests`) were never summarised; a student's program can only be edited by Admission Staff and is locked once the student has an enrollment record (`UpdateStudentProfile`), so a shift after enrollment had nowhere to be written down.

## Decisions

1. **Movement report.** `GET /analytics/enrollment-movements?academic_term_id=&type=drops|withdrawals|shifts[&college=]` returns aggregate counts only: `total`, `by_department`, and `groups` (per course of study for drops and withdrawals; "from → to" for shifts). Drops count approved drop requests and withdrawals approved withdrawal requests of the term, distinct students, demo accounts excluded. No student is named or identified.
2. **Who reads.** Registrar Head and Registrar Staff for every college (optional `college` filter); Program Head for their own college only. A shift counts for a Program Head when it leaves or enters their college.
3. **Recording a shift.** `POST /program-shifts` `{student_number, to_program_id, academic_term_id, reason}` by Registrar Head or Registrar Staff, into `program_shifts` (student, from course, to course, term, reason, recorded by, recorded at). The "from" course is the student's course at the moment of recording. A reason is required; a shift into the same course, an unknown student, or a demo account is refused.
4. **A record, not a transfer.** Recording does not change the student's `program_id` or curriculum. Doing that safely (credit mapping, curriculum resolution, the enrollment lock) is a separate decision; the screen says so.
5. **Idempotent and audited.** Recording the same student, term, from, and to again returns the existing row (200) and writes no second audit row; the first records `program_shift.recorded` with the before and after program and the reason.

## Consequences

- Enrollment Analytics gains Drops, Withdrawals, and Course Shifts tabs in the same navigation as its existing views; the Registrar Head records shifts from the Course Shifts tab.
- Course shifts recorded by the Registrar are only as complete as the Registrar makes them; nothing detects a shift automatically.
- Follow-up for the owner: whether recording a shift should also move the student's program and curriculum.

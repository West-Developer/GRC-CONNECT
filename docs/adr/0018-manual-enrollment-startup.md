# ADR 0018 — Registrar-controlled manual enrollment startup

**Status:** Accepted for the manual enrollment slice  
**Date:** 2026-08-02

## Context

The enrollment portal needs one clear starting point. Registrar Head opens one
school-year-and-semester record; Program Chairs then work manually through
curriculum capacities, faculty input, and schedule preparation. Predictive
analytics is paused until this manual process is stable.

## Decision

- Store one semester per `academic_terms` row and allow at most one
  non-archived term through the singleton current-slot record.
- Keep the lifecycle `draft → semester_ongoing → semester_closed → archived`
  (with `for_dean_approval` retained for the existing approval boundary).
- Archive is a Registrar Head-only, idempotent action. When the semester is
  ongoing it closes and archives it in one transaction; it never deletes
  attached academic records. The lower-level close transition remains for
  backward-compatible service callers.
- Create four independent college workflow rows (CCS, COE, COA, CBAE) for
  each new term. Program Chair visibility and writes are scoped to the stored
  college, not to a request-supplied college value.
- The Program Chair UI shows a waiting state when no actionable term exists,
  and keeps supporting navigation locked until the college reaches schedule
  preparation. All section counts and recommended section values remain
  manual.
- Seed data contains only six archived historical semesters (both semesters
  of 2020–2021 through 2022–2023). A clean seed intentionally creates no
  current term or demo enrollment records.

## Consequences

The Registrar must create the next semester before Chairs can plan. Historical
terms remain queryable for audit and reporting. A future term can be opened
only after the current semester is archived, preventing ambiguous enrollment
context. Predictive output can be added later without changing this manual
workflow contract.

# ADR 0032 — Published Section Change Control

**Status:** Accepted
**Date:** 2026-09-26
**Amends:** PRD §3.4 (Program Head owns section planning) and §3.7 (Registrar Head authority). Builds on ADR 0010 (room conflicts) and the schedule approval workflow (Dean, then Executive Director publishes).

## Context

Stakeholder Doc 14: once a schedule is approved, a Program Head must not be able to change it freely. The Program Head prepares a change on their side and sends it to the Registrar Head for approval. Changing only the professor needs no approval, but the Registrar Head must be told. The Registrar Head also needs a read-only view of the schedules Program Heads submit.

Before this change (verified in code): `PATCH /sections/{section}` was open to the Program Head for any section not in a submitted plan, including `published` ones, and the only professor-assignment notification fired the first time a professor was assigned.

## Decisions

1. **Lock trigger.** A section with status `published` is locked for a Program Head. Before that the existing planning rules (submitted-plan lock, return flow) apply unchanged.
2. **What is enforced.** `PublishedSectionLock` runs in `SectionController::update` after the policy check. On a published section it refuses (403) any payload that differs from the stored section in any field except `professor_id` and `override_reason`. Enforced on the API, not only in the schedule screen.
3. **Change request.** New table `section_change_requests` (section, requester, status `pending|approved|rejected|cancelled`, reason, `old_values`, `new_values`, decider, decided at, decision reason). Requestable fields: `schedule_days`, `starts_at_time`, `ends_at_time`, `room`, `modality`, `capacity`, `viability_threshold`. One pending request per section. The request is refused up front when nothing differs or when the proposed slot clashes (block section, professor, or room).
4. **Conflict rules shared.** The three clash checks moved out of `UpdateSectionRequest` into `SectionAssignmentConflicts` so a request, an approval, and a normal edit use the same code and messages.
5. **Decision.** Registrar Head only (`approve`, `reject` with a required reason). The requester may withdraw (`cancel`) while pending. Approving locks the request and the section, then re-checks that the section is still published, that every field named in `old_values` still holds, and that the new slot still does not clash. It then applies the change through `UpdateSection` and records the decision in one transaction, so nothing half-applies. Repeating a decision on an already-decided request is idempotent; the opposite decision is refused (422).
6. **Professor reassignment.** A Program Head may still change `professor_id` on a published section directly. `UpdateSection` then notifies every other Registrar Head (`section_professor_reassigned`) and records the audit row as `section.professor_reassigned`, with before/after. A newly assigned professor is not notified by this path (the existing first-assignment notice is unchanged).
7. **Visibility.** Registrar Head sees every request; a Program Head sees requests for their college's sections and the ones they filed. Registrar Staff has no access (open question for the owner).
8. **Registrar Head schedule view.** A read-only "Submitted Schedules" module (submitted plans plus published sections) reuses the schedule review dialog with decision buttons suppressed. No backend change was needed for reading.

## Consequences

- New audit vocabulary: `section.professor_reassigned`, `section_change_request.requested|approved|rejected|cancelled`; new auditable type `section_change_request`; new notification types `section_change_requested|approved|rejected` and `section_professor_reassigned`.
- Code paths that change published sections without a Program Head actor (Registrar Head approval, Executive Director publish or close) are unaffected.
- Not covered: the Dean adjusting a professor's assignment (S14/S15 will follow the same "no approval, notify the Registrar Head" rule).

# ADR 0031 — Registrar Head Prerequisite Waiver

**Status:** Accepted
**Date:** 2026-09-26
**Amends:** PRD §3.7 (Registrar Head authorized edge cases). Does not bring back the standalone "Overrides & Voids" module removed on 2026-09-23.

## Context

Stakeholder Doc 14 asks the Registrar Head to be able to override a student's subject taking. In this system the rule that blocks a subject is an unmet prerequisite (`BuildEligibleSubjectPool` / `PrerequisiteEvaluator`; only `Locked` grades count), including a prerequisite the student failed or has incomplete. The owner's assumption A2 (recorded in the Doc 14 handoff) was a narrow waiver, not a general "add any subject" override. Open question for the owner: is that enough?

## Decisions

1. **What a waiver is.** One row per student, subject, and academic term in `enrollment_subject_waivers` (unique on that triple): the student may take that subject in that term although a prerequisite is not met. It removes only the prerequisite block. Everything else still applies: the subject must be in the student's curriculum, must not be already completed, and needs an open section with seats; block restrictions are unchanged.
2. **Who.** Registrar Head only (`EnrollmentSubjectWaiverPolicy::manage`, plus `role:registrar_head` route middleware). Registrar Staff and Program Head cannot grant or revoke.
3. **Reason and audit.** A reason (3 to 1000 characters) is required. Granting and revoking each write an audit row with before/after and the reason (`enrollment_subject_waiver.granted` / `.revoked`).
4. **Idempotent.** Granting an already-active waiver changes nothing and writes no second audit row. Revoking sets `revoked_at` and keeps the row, so history stays; granting again re-activates the same row.
5. **Scope of the pool.** The waiver applies to the irregular student's eligible-subject pool (`prerequisite_waived` reason). It is deliberately not applied to the regular block pool, which is defined by the block, not by prerequisites.
6. **Where it lives in the UI.** Inside the Registrar enrollment review (the student information modal), not in a separate module.

## Consequences

- The Registrar Head can unblock a single subject without editing the curriculum or the student's grades.
- A broader override (adding a subject that is not in the curriculum, or overriding capacity) is not covered; it needs a new decision.
- The eligible-subject response gains the `prerequisite_waived` marker so the student sees why the subject is now offered.

# ADR 0037 — Admission requirements checklist

**Status:** Accepted
**Date:** 2026-09-26
**Amends:** PRD §3.2 (Admission Staff) and the Student portal scope.

## Context

Before this change the Admission process only had one flag, `requirements_verified_at`, set when Admission Staff created the student record. The stakeholder (Doc 14) wants a real checklist of the documents a student hands in, by student type, that Admission Staff tick off and the Student can see.

## Decisions

1. **Shared catalogue + per-student record.** `admission_requirement_types` (category `freshman | transferee | additional`, name, order, `is_active`, `is_system`) holds the list; `student_admission_requirements` holds, per student and requirement, `is_submitted`, `submitted_at`, `recorded_by`. A missing row means "not submitted". One row per pair (unique).
2. **Seeded exactly as the stakeholder listed.** Freshman: Form 137; Form 138; Good Moral Character; Certificate of Ratings. Transferee: Certificate of Grades; TOR (Original copy for GRC); Honorable Dismissal (Original); Good Moral Character (Original). Additional: 2 Pcs 2x2 Picture (White background & nametag); 2 Pcs 1x1 Picture (White background); Original Birth Certificate (PSA); Original Marriage Certificate (PSA if Married); (3PCS) LONG BROWN EXPANDED ENVELOPE; Latest Chest X-ray with normal result. They are inserted by the migration and marked `is_system`. Admission Staff can add more (any category) but cannot edit or remove the seeded ones. A name already in a category is refused.
3. **What a student is asked for.** The category matching `student_type` plus Additional. A student with no type recorded (legacy rows) is asked for the Additional list only. Ticking a requirement of the other type is refused (422).
4. **Who.** Admission Staff read any checklist, tick and untick, and add requirements (`StudentProfilePolicy::viewAdmissionRequirements` / `manageAdmissionRequirements`, `AdmissionRequirementTypePolicy::create`). A Student reads only their own (`GET /me/admission-requirements`), read-only. No other role has access.
5. **Idempotent and audited.** Setting a requirement to the state it already has changes nothing and writes no audit row. A real change writes `admission_requirement.updated` with `{student_profile_id, requirement, is_submitted}` before and after; adding a requirement writes `admission_requirement_type.created`.
6. **`requirements_verified_at` stays as it was.** It remains the explicit confirmation made when the student record is created; the checklist does not derive or overwrite it. The checklist's `summary.complete` says whether every applicable requirement is ticked. Whether completing it should gate anything (for example enrollment) is a policy decision that has not been made, so nothing is gated.

## Consequences

- Admission Staff get a checklist in Student Records; the Student portal gets an "Admission" module.
- The migration seeds data, so `migrate:rollback` removes the seeded rows with the tables.
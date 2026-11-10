# ADR 0041 — Admission Chooses the Enrollment Category and Student Type, and May Create an Account With Requirements Missing

**Status:** Accepted
**Date:** 2026-10-03
**Amends:** Stakeholder Doc 17 (Category and Type derived from Year Level at intake) and ADR 0037 (the Admission requirements checklist: the single "requirements verified" confirmation at account creation).

## Context

At Admission intake the Enrollment Category and the Student Type were set automatically from the Year Level (Year 1 became Regular/Freshman, Years 2-4 became Irregular/Transferee) and the form showed them as read-only badges. Admission asked for the opposite: they know whether the student is Regular or Irregular, and what kind of student they are (a transferee, a returnee, or someone who already studied here), so the two should be dropdowns, not automatic. On the same form, the single "Requirements submitted and verified" checkbox had been replaced by the requirements checklist, and Admission then asked that the account may still be created when some requirements are not yet handed in.

## Decisions

1. **Both are dropdowns, with no default.** Enrollment Category: Regular, Irregular. Student Type: Freshman, Transferee, Returnee, Existing Student (Freshman was left out of the first version of this form and added back the same day at Admission's request). Both are required (`POST /student-profiles` returns 422 without them) and the form clears them after each account so every account gets a deliberate choice. The edit dialog uses the same dropdowns, and a year-level correction no longer recomputes them (`UpdateStudentProfile` no longer touches them).
2. **A new type, `existing_student`.** Added to `StudentType` (label "Existing Student"). It is exempt from the credit-mapping gate like a Freshman (an existing student has records at the school); Transferee and Returnee are still gated. `Freshman` is offered again in both the Create Account form and the edit dialog (it had been dropped from the first version of the dropdown; Admission asked for it back). The API always accepted it, so no backend change was needed.
3. **The requirements checklist follows the chosen type.** `GET /admission-requirement-types?student_type=` returns Freshman or Transferee requirements plus Additional; Returnee and Existing Student get the Additional list only. **The Existing Student rule is an assumption**, not an approved policy value: the Additional-only list mirrors what a Returnee gets. `year_level` is still accepted on that endpoint for older callers.
4. **Requirements may be missing.** `requirement_type_ids` may be partial or empty. It may only name requirements that apply to the chosen type (422 otherwise). Each ticked id is saved on the new student's checklist and the rest stay there for Admission to tick later. `student_profiles.requirements_verified_at` and `requirements_verified_by` are now set only when every applicable requirement was ticked (or, for a caller that sends no list, when it sent `requirements_verified`); otherwise they stay `NULL`.
5. **Inactive programs cannot take new students.** The Create Account program list shows active programs only (BS Criminology is inactive and has no students or curriculum). The edit dialog still lists every program, so a record on an inactive one can be read.

## Consequences

- Nothing is migrated: `student_type` and `enrollment_category` are plain strings, and existing rows keep what they have. Older frontends that omit the two fields get a 422 until they reload.
- The grade-based reclassification (ADR 0021) is unchanged and still overrides `enrollment_category` each term; `enrollment_category_derived_at` stays `NULL` for what Admission chose.
- Not decided here: whether an Existing Student should be asked for any requirements at all. (Whether Freshman should be offered was settled afterwards: it is.)

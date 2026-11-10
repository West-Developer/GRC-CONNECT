# ADR 0040 — The Student Reviews the Program Chair's Changes to an Irregular Enrollment

**Status:** Accepted
**Date:** 2026-10-03
**Amends:** ADR 0030 (the Program Head stage of an irregular or overload enrollment). The route for an enrollment the Chair simply approves or rejects is unchanged.

## Context

An irregular student submits subjects one by one. Before ADR 0040 the Program Chair could add or remove
subjects on that submission (`ReviseEnrollmentSubjects`) and then approve it; the student was only
sent a notice to "check your Enrollment page", had no way to say no, and the Chair's reason (if any)
lived in an optional free-text comment on the approval. Stakeholder feedback (2026-10-03) asked that the
enrollment go **back to the student** when the Chair edits it, with the Chair's notes so the student can
see why; that if the student **accepts** it goes straight to the Registrar for approval; and that if the
student **does not accept**, it goes back to the Program Chair with the **student's reason**.

## Decisions

1. **A new status, `pending_student_review`** ("Awaiting Student Review"). It is a non-terminal status,
   so seats stay held and the "one active enrollment per student and term" generated column
   (`enrollments.active_academic_term_id`, built from the terminal list) needs no migration.
   `enrollments.status` is a plain string, so no schema change is needed for the value either.
   Route: `pending_program_head_approval` → (Chair changes subjects) → `pending_student_review` →
   accept → `pending_registrar_approval`, or decline → back to `pending_program_head_approval`.
2. **Only a change of subjects sends it to the student.** A Chair who approves or rejects without
   changing anything behaves exactly as before. Saving a revision now requires a **note** (why the
   subjects were changed; the student reads it) and must actually add or remove at least one subject.
3. **Each trip is recorded** in `enrollment_revisions` (migration `2026_10_03_000002`): the note, the
   subjects added and removed (snapshots, so history still reads correctly if a section changes), units
   before and after, the status (`pending` / `accepted` / `declined`), the student's reason and when they
   answered. A second round after a decline is a second row. The enrollment API returns them as
   `revisions`, oldest first. The student sees them on the Enrollment page; the Chair and the Registrar
   see them in the review dialog.
4. **The student's answer** is `PATCH /enrollments/{id}` with `action` `student_accept_revision` or
   `student_decline_revision` (a reason is required for the second). Only the owning student may send
   them (`EnrollmentPolicy::respondToRevision`), and only while the status is `pending_student_review`.
   Accepting stamps `program_head_decided_at` (the Chair proposed the changes, so the Program Head stage
   is complete) and the enrollment appears in the Registrar's queue. Declining leaves the stage open and
   the Chair sees the reason in the "Pending Review" list and in the dialog.
5. **While the student decides**, the Chair cannot approve or reject (422), the Registrar sees the
   enrollment view-only, and the student can still cancel (`student_cancel`) and the Registrar can still
   void it. After a decline the Chair may approve, reject, or change the subjects again.
6. **Seats.** The Chair's revision applies at once (seats move as before, and stay held while the student
   decides); a decline does not restore the student's original subjects, the Chair decides what happens
   next. Cancelling releases whatever the enrollment holds, as before.
7. **Overload.** Because the Chair no longer presses Approve on this path, a revision whose new load needs
   overload approval must carry `overload_acknowledged` (FR-ENR-004), the same acknowledgement Approve asks for.
8. **Notices.** The student gets `enrollment_program_head_subjects_revised` with the Chair's reason. The
   Chair gets `enrollment_revision_accepted` / `enrollment_revision_declined` (with the reason) and, on
   accept, the Registrar gets `enrollment_program_head_approved` (the existing "now awaits you" notice).
   The Chair notices go to the Program Chairs of the student's college and to any chair with no college.

## Consequences

- Enrollments already at `pending_program_head_approval` are unaffected. There is no data migration.
- The frontend treats `revisions` as optional on read (defaults to none), so it works against a backend
  that predates this change; the student queue view, dashboards, status charts, the IT Control student
  accounts list and the stuck-enrollments list all know the new status.
- Not decided here: a time limit on the student's answer (an enrollment can wait for the student
  indefinitely; the stuck-enrollments dashboard flags it by age like any other stage), and a way for the
  student to counter-propose their own subjects (a decline only returns it with a reason).

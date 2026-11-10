# ADR 0056 — The Registrar Head Withdraws a Student's Subjects, and the Prospectus Shows Them as W

**Status:** Accepted
**Date:** 2026-10-22
**Amends:** PRD §3.7 (Registrar Head), PRD §4.2 rule 7 (withdrawal), the grading scale in `GradeMark`.

## Context

The system could only withdraw a student's **whole enrollment**: the student requests, Registrar Staff or the Registrar Head decide (FR-FIN-004). The owner asked (2026-10-22) for an option for the **Registrar Head** to withdraw a student from **individual subjects**, and for the Prospectus to show which subjects were withdrawn. The COR's own withdrawal terms already say "a grade remark of W (Withdrawn) shall be entered".

## Decisions

1. **A new mark, `W` (Withdrawn).** `GradeMark::Withdrawn = 'W'`: not numeric, not passing, no effect on the GPA, and it blocks Regular standing like `DRP`, so the subject has to be taken again. `academic_grades.mark` is a plain string, so **no migration** is needed. `W` is written only by the withdrawal below: the three grade-encoding requests (single grade, grade update, section grade drafts) refuse it, so a professor can never encode it.
2. **One action, `WithdrawEnrolledSubjects`, Registrar Head only** (`role:registrar_head` on the route plus a check in the controller). For each chosen subject of the student's `enrolled` enrollment, in one transaction with the profile, enrollment and subject rows locked: the enrollment subject becomes `dropped` (the schedule, COR, rosters and demand counts already ignore dropped subjects), the section seat is released once (only when `enrollment.withdrawal.releases_seats` is true, the same switch the whole-enrollment withdrawal uses), and a **locked `W` grade** is written with the reason in `remarks` and the Registrar Head as `encoded_by`. A draft or submitted grade for that subject and term is turned into the `W`; a **locked** final grade blocks the withdrawal (422).
3. **Idempotent.** A subject that is no longer active is refused (422), so a repeated request never releases a seat or writes a grade twice. A subject of another student, an enrollment that is not `enrolled`, and a closed or archived term are refused too.
4. **Last subject withdraws the enrollment.** When no active subject is left on an enrollment it becomes `withdrawn`, which frees the student's active-enrollment slot for the term, the same state a whole-enrollment withdrawal ends in.
5. **No fee change.** The assessment stays as billed. What the student owes follows the COR's withdrawal terms, and GRC has no refund process (ADR 0046). The owner has not given a rule for recomputing fees on a subject withdrawal, so none is invented.
6. **Audit and notice.** Every subject writes `enrollment_subject.withdrawn` (on the `W` grade, with the reason). The student gets one `subject_withdrawn` notice naming the subjects and the reason.
7. **The Prospectus shows it.** A withdrawn subject reads `W` in the Grade column with status **Withdrawn** (own tint and badge), is not counted as completed in the year progress, and stays in the Add/Drop panel's remaining subjects so the student can take it again. A later retake's grade replaces it as the latest result; the `W` stays in the attempt history.
8. **UI.** A new Registrar Head module, **Subject Withdrawals**: find a student by number or name, tick the enrolled subjects, give a reason (3+ characters), confirm. The same page lists what was already withdrawn with who, when and why. API: `GET` and `POST /api/v1/students/{studentProfile}/subject-withdrawals`.

## Consequences

- No migration. Deploy is code only.
- Withdrawals approved through the existing **whole-enrollment** flow still do **not** write `W` marks, so those subjects do not show as Withdrawn on the Prospectus. That is a possible follow-up; it was not asked for.
- Open for the owner: a withdrawal window (the COR says "until the week after the Midterm") is **not enforced**, because the system has no midterm date; the Registrar Head decides. Whether fees should change by withdrawal week is also open (decision 5).

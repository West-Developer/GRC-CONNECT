# ADR 0028 — Year-Level Promotion and Back Subjects

**Status:** Accepted
**Date:** 2026-09-25
**Amends:** the "no failed subjects" promotion rule in the `PromoteEligibleStudents` docblock (stakeholder Doc 3, Part B), and the clause of `docs/superpowers/specs/2026-08-19-term-scoped-enrollment-standing-design.md` that made a backlog subject with no section offered this term "invisible" to the standing check. ADR 0020 (block enrollment for Regular, per-subject for Irregular) and ADR 0021 (the classifier re-derives on every grade lock) are unchanged.

## Context

Stakeholder feedback (Google Doc "WESTLIE", 2026-09-25) reported student 2026-06-01067
(`lara.quiambao@grc.com`; `erlinda.valencia@grc.com` had the same problem). All of the
student's 1st-year grades had been entered, one subject (ETHICS) came out 5.00, and the
system still showed "1st Year · Regular" with a disabled `Choose FIL101` button and the
message "ARTAPP has already been completed … See the Registrar". The stakeholder's words:
the student should be 2nd Year with one back subject, and should be able to enroll.

Verified in code (the dev database was not reachable when this was written):

1. `PromoteEligibleStudents` promoted only when **every** required subject of the year was
   passed, and nothing in grade entry triggered it (only the daily scheduler). A single 5.00
   held the student in the old year indefinitely.
2. `ClassifyEnrollmentStanding` counted a failed backlog subject only if a non-block, open
   section existed this term, and returned "undetermined" for the whole year level when no
   block was published. A 1st-year student with one failure therefore classified as Regular,
   and `BuildEnrollmentBlockPool` then disabled the year's block because the student had
   already passed most of it. Nothing on that path let the student enroll.
3. `BuildEnrollmentScheduleSummary` (the banner) and the top of `BuildEligibleSubjectPool`
   read the **stored** `enrollment_category`, while the block pool used the live standing.

## Decisions

**1. A year is complete when every required subject has a final locked grade.** Credited
(curriculum migration or approved transferee credit, ADR 0026), passed, or closed out with a
failing mark (`5.00`, `NC`) all count. A subject with no locked grade, or whose latest mark
is `INC`/`DRP`, is not final and still holds promotion. A later passing retake counts as
passed.

**2. A failed subject becomes a back subject; it does not block promotion.** The student
moves up a year; the failed subject stays behind them in the curriculum.

**3. Back subjects make the student Irregular whether or not a section is offered.**
`ClassifyEnrollmentStanding` flags a required subject that is placed earlier than the
student's current position, is not credited, was last graded with a mark that
`GradeMark::blocksRegularStanding()` (Failed, NC, INC, DRP), and was graded in a **prior**
term. The reason code stays `needs_adding_backlog` (seeders and consumers assert it); only the
message differs. Two rules are kept on purpose:

- A result recorded in the *current* term does not flip standing until the next term (Doc 7,
  "kapag may bagsak na subject dapat next sem na sya magiging irregular").
- A failed subject that is part of this term's own block is a repeat inside the block, not a
  back subject.

A never-taken backlog subject still counts only when a section is open this term (unchanged;
the 2026-08-19 spec's Amurao case). "Undetermined" (`null`) now means only "no block yet **and**
nothing else settles it": a failed back subject settles it. (ADR 0047 adds one more case: a
student with no record in the system at all is also undetermined.)

**4. Promotion runs when a grade is locked, not only nightly.** `PromoteEligibleStudents::
promoteStudent()` is called after `UpdateAcademicGrade` (lock, and INC resolution) and after
`LockAllAcademicGrades`, **before** reclassification, so the classifier already sees the new
year level. It is idempotent (the next year has no grades, so a repeat finds nothing to do).
The daily `academic:promote-year-levels` command stays as a backstop and its output now lists
each student's back-subject codes, so a dry run shows who becomes Irregular.

**5. One standing everywhere.** The schedule summary's viewer audience and the eligible-subject
pool's Irregular filter use the live audience from `BuildEnrollmentAccessContext`, the same
value the block pool uses, so the banner, the block list and the subject list cannot disagree
during the request that self-heals a stale stored category.

## Non-goals and open points

- No promotion on page load (a GET must not change `year_level`). Existing students are
  repaired by running the command once (dry run first).
- No change to how a back subject is *scheduled*: it is retaken per subject, needs an offered
  section, and block-exclusive sections still follow the irregular window (ADR 0020).
- Policy values are unchanged. Whether the school caps the number of back subjects is an open
  institutional question; this ADR does not invent one. If a cap is introduced later it belongs
  in `PromoteEligibleStudents` next to the completion rule.
- Sibling subjects (same code and units under different colleges) are not treated as
  equivalent by the promotion rule, matching `ClassifyEnrollmentStanding`; `BuildEligibleSubjectPool`
  remains the only place that resolves siblings.

# ADR 0051 — A Subject the Student Is Credited For Is Taken Out of Their Block, Which Makes Them Irregular

**Status:** Accepted
**Date:** 2026-10-15
**Amends:** ADR 0021 / the term-scoped standing rule (`ClassifyEnrollmentStanding`), ADR 0026 (an approved credit counts), ADR 0050 (a credit the Program Chair passed on counts provisionally).

## Context

The owner reported a student (a Transferee, BS Information Technology) whose NSTP 1 shows as "Credited" on the Prospectus yet who was still treated as Regular and offered the whole block (14 subjects, NSTP 1 among them). A credited subject is not a seat to take again, so the system must neither offer nor recommend it, and the student must become Irregular and enrol per subject. It must do the same for every Transferee and Returnee.

The classifier skipped a block subject the student had "completed early", and its completed test included credited subjects, so a credit never counted against being Regular. The block pool, which decides what a Regular student may submit, looked only at grades, so it neither noticed a credited subject in the block nor a credited prerequisite.

## Decisions

1. **A credited block subject makes the student Irregular.** In `ClassifyEnrollmentStanding`, a subject of the term's standard block that the student is credited for (an approved transferee credit, one the Program Chair passed on and the Head has yet to decide (ADR 0050), or a returnee's curriculum migration credit) adds the reason `needs_removing_credited` ("CS201 is already credited, so it is taken out of your block."). The student is then routed to the per-subject flow, whose pool already lists a credited subject as "completed" and not eligible, so it is not offered or recommended. A credit that is still with the Program Chair, or that the Head rejected, is not a credit and changes nothing.
2. **A subject passed with a grade keeps its old rule.** Passing a block subject early does not make a student Irregular (a real-data regression of 2026-08: a subject offered either semester that the student had already passed misclassified most of the Irregular population). Only credits trigger the new rule.
3. **The block pool agrees with the classifier.** `BuildEnrollmentBlockPool` counts a credited prerequisite as met (it looked only at grades, so a student the classifier called Regular because a credit covered a prerequisite would have found their block withheld). A credited subject inside a block needs no separate check there: such a student is Irregular, the pool returns no blocks for them, and a block submission is refused (`block_code`), which the endpoint tests pin.
4. **It heals itself.** `BuildEnrollmentAccessContext` re-derives the category each time a student uses Enrollment during the ongoing term and saves it (attributed to a Registrar Head), and the Chair's endorse and the Head's approve, reject and return already reclassify (ADR 0050); `students:reclassify` does the same for everyone.

## Consequences

- A Transferee or Returnee credited for any subject of their year level's block is Irregular and enrols per subject, with the Program Chair's review that Irregular enrolments already have.
- On production the rule touches one student (the reported one); nobody else has a credit inside their block.
- No migration. The reason code is new in the reclassification notice and the audit row.

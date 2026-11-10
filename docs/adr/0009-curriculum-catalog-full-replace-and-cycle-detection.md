# ADR 0009 — Curriculum Catalog: Full-Replace Writes and Scoped Cycle Detection

**Status:** Accepted
**Date:** 2026-07-27

## Context

PRD §5.1 FR-SCH-001 requires managing curricula, subjects, year levels,
terms, and prerequisites; FR-SCH-002 requires rejecting direct or transitive
prerequisite cycles. PRD §8.4 lists exactly `GET /api/v1/subjects`,
`GET /api/v1/curricula`, `POST /api/v1/curricula`, and
`PATCH /api/v1/curricula/{curriculum}` — no separate endpoints for
`curriculum_subjects` or `subject_prerequisites`. This slice is also the
first production consumer of the `role` middleware built in ADR 0008.

## Decisions

**Nested aggregate, not separate endpoints.** `curriculum_subjects` (a
subject's placement within one curriculum) and `subject_prerequisites` (what
that placement requires beforehand) are managed only through the `subjects`
array nested in the `POST`/`PATCH /curricula` payload, not as their own
routes. This matches the literal PRD §8.4 endpoint list rather than
inventing new ones, and treats a curriculum as a single aggregate root — the
same reasoning that kept `programs`/`academic-terms` read-only until a
concrete write need appears.

**Full replace, not incremental diffing.** Every `subjects` array submitted
to `POST` or `PATCH /curricula` is the *complete* desired set of placements
and prerequisites for that curriculum; the server deletes and recreates
`curriculum_subjects`/`subject_prerequisites` to match
(`App\Actions\Curriculum\SynchronizeCurriculumSubjects`). PRD does not
specify incremental-update semantics for this resource, and a full replace
is simpler to reason about, simpler to test, and avoids partial-update edge
cases (what happens to a placement's prerequisites when the placement itself
isn't mentioned in a PATCH?) that PRD leaves unanswered. The
`curriculum_subjects.curriculum_id` foreign key cascades on delete, so
replacing a curriculum's placements also removes their prerequisite rows
without a separate cleanup step.

**Cycle detection is a pure graph algorithm, decoupled from persistence.**
`App\Domain\Curriculum\PrerequisiteCycleDetector::hasCycle()` takes a plain
list of `{subject_id, prerequisite_subject_id}` edges and runs a standard
DFS with cycle detection (visiting/visited sets, back-edge check). It knows
nothing about Eloquent or the database. Both `StoreCurriculumRequest` and
`UpdateCurriculumRequest` build the edge list from the submitted payload
alone and call the detector inside `withValidator()`, so a cyclic submission
fails as an ordinary 422 `VALIDATION_FAILED` response — through the exception
handling already in place — before anything is written. Because the check
runs against the full-replace payload rather than a partial diff against
existing rows, the same logic is correct for both create and update with no
special-casing.

The graph is scoped to one curriculum submission by construction: edges only
exist between subjects mentioned in that payload's own `subjects` array. A
`prerequisite_subject_id` may reference any subject in the catalog (the
foreign key target is `subjects`, not `curriculum_subjects`), but if that
subject isn't also a placement in this curriculum, it simply has no outgoing
edges in this check — it cannot participate in a cycle it isn't part of.

**Program Chair is the sole write role.** `POST`/`PATCH /curricula` are
gated by `role:program_chair` at the route level and re-checked by
`CurriculumPolicy::create()`/`update()` — the first real route consumer of
`EnsureUserHasRole` (ADR 0008 shipped it with no production route). This
matches the frontend's existing `program_chair` module ownership
("Curriculum", "Subjects & Prerequisites" in
`frontend/src/app/portal/role-capabilities.ts`), not a new policy invented
for this slice.

**`SubjectStatus`/`CurriculumStatus` are provisional**, following the same
discipline as `ProgramStatus`/`AcademicTermStatus` (ADR 0008) and
`SANCTUM_TOKEN_EXPIRATION` (ADR 0003): PRD §17 leaves institutional status
vocabularies unconfirmed. `CurriculumStatus::Draft` is not learner-visible;
`Active` and `Archived` are (a student already following an archived
curriculum still needs to see it). Both columns stay `VARCHAR`.

**Foreign keys use explicit delete behavior per PRD §10.6:**
`curricula.program_id` and `curriculum_subjects.subject_id` /
`subject_prerequisites.prerequisite_subject_id` `restrictOnDelete()` — a
program or subject in active use cannot be silently removed.
`curriculum_subjects.curriculum_id` and
`subject_prerequisites.curriculum_subject_id` `cascadeOnDelete()` — these
rows are owned by their parent and have no meaning without it. This is the
first slice to use foreign keys at all; the identity-foundation slice (ADR
0007) deliberately avoided them.

## Consequences

- Any future partial-update requirement for curriculum subjects (e.g., "add
  one subject without resubmitting the whole plan") is a breaking change to
  this request shape, not an additive one — flag this if it comes up.
- The cycle detector operates in-memory over whatever edges it's given; a
  future sub-project that needs a curriculum-wide validity check *outside*
  a write request (e.g., an integrity audit) can reuse it directly by
  loading the existing DB rows into the same edge shape.
- `CurriculumStatus`/`SubjectStatus` need a data migration, not a schema
  change, once GRC confirms the real vocabulary — same as every other
  provisional enum in this codebase.

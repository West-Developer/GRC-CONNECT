# ADR 0020 — Five-audience enrollment windows and block-as-unit enrollment

**Status:** Accepted
**Date:** 2026-08-04

## Context

The enrollment feature existed in shape but was inert at runtime, for three
compounding reasons:

1. Two migrations (`academic_term_enrollment_windows`,
   `room_catalog_entries`) had never applied anywhere, because
   `grc_migrator`/`grc_test` held only table-by-table grants rather than a
   database-level grant — any *new* table's `CREATE` was silently denied.
2. The frontend and backend disagreed about the enrollment-window shape.
   The backend sent `audiences` (5 entries: `year_1`..`year_4`, `irregular`);
   the frontend's `.strict()` schema demanded `year_levels` (4 entries).
   Every read threw a contract error and every Registrar save 422'd, so no
   student ever saw a window banner and Submit was never actually gated.
3. `sections.is_block_exclusive` and `student_profiles.enrollment_category`
   existed as columns but nothing wrote to them — 212 of 212 sections carried
   `is_block_exclusive = null`, so the block-restriction rule was dormant
   regardless of the first two problems.

On top of unblocking those three, this slice needed: irregular-student
enrollment as a fifth, distinct audience (not a year level); a fixed
8:00 AM–11:59 PM enrollment day per ADR 0018's existing time convention;
Program Chair capacity control that actually reaches generated sections
(206 of 212 were stuck at a hardcoded default of 40); and moving school-year
creation from a standalone Registrar form to the tail end of an Archive
action.

## Decision

**Five audiences, not four year levels.** `App\Domain\Enrollment\EnrollmentAudience`
is `Year1|Year2|Year3|Year4|Irregular`, each with its own row in
`academic_term_enrollment_windows` (one term ↔ up to 5 windows, unique per
`(academic_term_id, audience)`). `EnrollmentAudience::label()` returns
`"1st Year"`..`"4th Year"`/`"Irregular Students"` — never `"Year N"`.
`EnrollmentWindowResolver` (status-first, then window, per ADR 0018's
ordering — see the class's own docblock) already read this shape; the fix
here was making the frontend agree with it (`year_levels` → `audiences`,
`year_level` → `audience`) rather than changing the resolver.

**Time-based block exclusivity, not a static flag check.** The old
`isBlockRestricted()` was a pure function of the section alone and let any
regular student see any year's blocks. `App\Domain\Enrollment\BlockSectionAccessPolicy`
replaces it with a rule that also depends on who's asking and when:

1. A section that isn't block-exclusive (including `null`, for legacy rows) —
   always visible.
2. The viewer is irregular — visible only once the irregular window is open.
3. The section's block year level can't be determined — visible rather than
   silently hidden (fail open, not closed).
4. Otherwise — visible only if the block's year level matches the viewer's
   own audience.

`App\Actions\Enrollment\BuildEnrollmentAccessContext` resolves all 5
audience windows once per request into a pure `EnrollmentAccessContext`
value object, shared by `BuildEligibleSubjectPool` (which now determines a
block's year level authoritatively from `section.sectionPlan.year_level`,
falling back to a section-code regex only for legacy rows) and by the new
block pool below.

**Regular students enrol by block; irregular students enrol by subject.**
This is a deliberate audience-level UX split, not a technical convenience:
a regular student's block is a single radio choice covering their whole
term's course load; an irregular student never has a clean block to choose
from by definition, so they keep the existing per-subject picker.
`App\Domain\Enrollment\EnrollmentBlock` (block code, year level, its
sections, total units, `seatsRemaining` = **MIN** across every section in
the block — the binding constraint — `isSelectable`, and non-silent
`reasons`: `block_full`, `incomplete_schedule`, `prerequisite`,
`partially_completed`, `already_enrolled`, `window_closed`) is built by
`BuildEnrollmentBlockPool`, which returns `[]` for irregular viewers by
design. This is a new `GET /enrollment-blocks` endpoint rather than an
extension of `/eligible-subjects`, because that schema is `.strict()` and
shared by two other screens, and because the cardinality genuinely differs
(one block choice vs. N independent subject choices).

**One submission path, not two.** `POST /enrollments` was extended rather
than duplicated: `StoreEnrollmentRequest` accepts `sections` *or*
`block_code` (mutually exclusive via `required_without`/`prohibits`),
resolving `block_code` server-side to its section ids through the same
`BuildEnrollmentBlockPool`. A block submission skips
`rejectIneligibleSections()` — that check 422s on any `completed` or
`already_selected` subject, which is wrong for a block containing a repeated
subject; the block pool's own `partially_completed` reason is the correct
gate there instead. Window, duplicate, conflict, and overload checks are
unchanged and still apply.

**Capacity: per-year default, per-section override, and a flag to keep them
from fighting.** `academic_term_section_plans.students_per_block` is the
Program Chair's per-year default; `sections.capacity_source`
(`plan`|`manual`) records whether a given section's capacity was last set by
that default or by a manual override. `SaveSectionPlan::release()` — a
`firstOrCreate` that never updated an existing row, the root cause of 206
sections stuck at capacity 40 — became an `upsertGeneratedSection()` that
only touches `capacity` when `capacity_source === Plan`, and always sets
`is_block_exclusive = true`. `UpdateSection` sets `capacity_source = Manual`
whenever a human changes a section's capacity by hand, so a later
regenerate-from-plan pass won't stomp it.

**Seat concurrency: lock the whole block, not one section at a time.**
`SubmitEnrollment` now fetches every section in the submission — up to
~7 for a block — with `orderBy('id')->lockForUpdate()` inside the
transaction (deterministic lock order avoids a two-block deadlock over a
shared subject), re-checks each section's remaining seats, and rolls back
the entire submission if any one section has none left. A block enrolls
all-or-nothing.

**Archive, then create — not create, then archive.** The standalone
"Create school year and semester" form is gone. `ArchiveAndCreateNextTerm`
composes the existing archive transition with `CreateAcademicTerm` (Draft,
no dates) in one transaction, triggered by a new
`POST /academic-terms/{term}/archive-and-create-next` and a small dialog
that asks only for the next school year and semester — enrollment dates are
still set afterward, on the Enrollment schedule card, per the existing
division of concerns. The standalone create form is kept, but only as an
empty-state affordance shown when no non-archived term exists at all (e.g.
the very first term ever).

**Amendment to ADR 0018.** ADR 0018 stated "a clean seed intentionally
creates no current term." That is no longer true: `AcademicTermSeeder` now
also creates `2026-2027 / 1st` as the current Draft term (plus its 4 college
workflow rows and 5 staggered audience windows), because a Program
Chair/student/Registrar demo walkthrough needs something to act on
immediately after a fresh seed. The six-archived-terms baseline from ADR
0018 is unchanged; this only adds the seventh, current row.

## Consequences

A regular student now sees exactly one meaningful choice — which block —
with that block's full weekly schedule visible before committing, instead
of assembling a schedule subject-by-subject. An irregular student's window
can be staggered independently of every year level, and until it opens they
cannot see or claim block-exclusive seats even if the year-level window
happens to overlap; when both are open at once, irregular students do
compete with regular students for the same block seats — this is a
Registrar scheduling choice enabled by the design, not a bug to guard
against.

`capacity_source` means the Program Chair's per-year default and a
dispatcher's one-off manual override can coexist indefinitely: regenerating
a plan never silently reverts a manual change, and a manual change never
prevents the next year-level-wide capacity update from reaching every other
section.

`max_regular_units` in `config/enrollment.php` is still `null`, so
`rejectOverload()` remains a no-op — nothing stops a 24-unit block from
being submitted. This is pre-existing, unchanged behavior, not a new gap
introduced here.

`grc_app` (the narrow-grant runtime user) needed explicit
`SELECT,INSERT,UPDATE,DELETE` grants added for both new tables after the
migrations finally ran — the migrator/test users' database-level grant does
not extend to the runtime user by design, and the test suite's broader
grants had been masking this gap. Any future new table needs the same
explicit `grc_app` grant step; it will not happen automatically.

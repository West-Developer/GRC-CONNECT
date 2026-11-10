# ADR 0010 — Section Planning: Scope of Conflict Detection

**Status:** Accepted
**Date:** 2026-07-28

## Context

PRD §5.1 FR-SCH-004 requires managing sections (capacity, schedule, room,
professor assignment); FR-SCH-005 requires "conflict detection." The
`sections` table and `Section` model were found already written (see the
schedule-and-enrollment schema-foundation task) with `schedule_days` as a
free string (`"MWF"`, `"TTh"`, `"Sat"` — the shorthand `SectionSeeder` already
seeds), unlike `FacultyAvailability.day_of_week`, which uses an explicit
ISO-8601 integer specifically to avoid this ambiguity. This slice has to
decide both *what* FR-SCH-005 actually checks and *how* to compare two
differently-shaped day representations.

## Decisions

**Conflict detection is scoped to same-professor double-booking only.**
`App\Domain\Scheduling\SectionConflictDetector::hasConflict()` flags a
conflict only when the *same professor* is assigned to two sections, in the
*same term*, whose declared days share at least one day and whose time
ranges overlap. Room conflicts and faculty-availability matching (whether an
assigned slot falls within a professor's declared `FacultyAvailability`
windows) are **not** enforced. Neither the found schema nor its seed data
gives evidence either is a hard rule — `room` is a free string with no
uniqueness constraint, and nothing links `sections` to
`faculty_availabilities` at all. Inventing either rule now would repeat the
mistake PRD §17 already flags elsewhere: encoding an unconfirmed policy as if
it were settled. If GRC confirms either rule later, it is additive: a new
check alongside this one, not a replacement.

**`schedule_days` shorthand is parsed, not compared as an opaque string.**
`App\Domain\Scheduling\ScheduleDayParser::parse()` reads the multi-day
shorthand into a list of ISO-8601 day-of-week integers (1 = Monday …
7 = Sunday) — the same numbering `FacultyAvailability.day_of_week` already
uses, so the two are comparable if a future slice needs to check assignment
against declared availability. Parsing is greedy and longest-token-first
(`Th`/`Sat`/`Sun` checked before the single-letter `T`/`S` fallbacks, so
`"TTh"` parses to Tuesday+Thursday, not Tuesday twice), and stops at the
first unrecognized character rather than guessing — a malformed string
yields a partial or empty day list, never a wrong one. This is not a PRD
vocabulary; it is the convention already present in `SectionSeeder`'s own
data, made precise enough to reason about.

**Conflict detection is a pure function, decoupled from persistence** — the
same shape as `PrerequisiteCycleDetector` (ADR 0009).
`StoreSectionRequest`/`UpdateSectionRequest` query the professor's *other*
sections in the submitted term (excluding the section being updated), reduce
them to `{schedule_days, starts_at_time, ends_at_time}` tuples, and call the
detector inside `withValidator()` — a cyclic-equivalent rejection surfaces as
an ordinary 422 `VALIDATION_FAILED`, not a database error. The check is
skipped entirely when `professor_id` is absent (a section may be planned
before a professor is assigned) or when either side of a comparison is
missing schedule information.

**Time overlap is a half-open interval check**
(`start1 < end2 && start2 < end1`), so two slots that merely touch at a
boundary (one ends exactly when the next begins) do not conflict — the same
reasoning as a calendar back-to-back booking.

**Program Chair is the sole write role**, matching curriculum authorship
(ADR 0009) — sections are the chair's schedule plan. `capacity`,
`viability_threshold`, and `enrolled_count` are exposed read-only on the
Resource; nothing in this slice enforces the viability threshold, which stays
informational per the model's own docblock until PRD §17 confirms it.
`enrolled_count` is never client-writable — it is a maintained counter
derived from `enrollment_subjects`, not an input.

## Consequences

- A future room-conflict or availability-matching requirement is additive: a
  second check called alongside `SectionConflictDetector`, not a rewrite of
  it.
- `ScheduleDayParser` is reusable wherever `schedule_days` needs to be
  compared against an ISO-8601 day number — for example, if a later slice
  cross-checks section assignments against `FacultyAvailability`.
- If GRC ever confirms a stricter `schedule_days` format (e.g., a fixed enum
  of day-combinations, or numeric days like `FacultyAvailability`), this
  parser becomes redundant rather than wrong — the shorthand it reads would
  simply stop appearing.

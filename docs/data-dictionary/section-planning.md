# Section Planning Data Dictionary

**Database:** MariaDB 10.4.32 (`grc_enrollment` / `grc_enrollment_test`), per
ADR 0007.

Migration: `backend/database/migrations/2026_07_27_000008_create_sections_table.php`
(schema landed in the schema-foundation task; API layer added in this
slice — see ADR 0010 for the conflict-detection design).

## `sections`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `academic_term_id` | `BIGINT UNSIGNED` | not null, FK → `academic_terms.id`, `RESTRICT` on delete | |
| `subject_id` | `BIGINT UNSIGNED` | not null, FK → `subjects.id`, `RESTRICT` on delete | |
| `section_code` | `VARCHAR(255)` | not null | e.g. `A`, `B` |
| `professor_id` | `BIGINT UNSIGNED` | nullable, FK → `users.id`, `SET NULL` on delete | A section may be planned before a professor is assigned |
| `schedule_days` | `VARCHAR(255)` | nullable | Shorthand (`MWF`, `TTh`, `Sat`) — see `App\Domain\Scheduling\ScheduleDayParser` |
| `starts_at_time`, `ends_at_time` | `TIME` | nullable | |
| `room` | `VARCHAR(255)` | nullable | Free string — room-capacity source and conflict rules unconfirmed (PRD §17) |
| `capacity` | `SMALLINT UNSIGNED` | not null | |
| `viability_threshold` | `SMALLINT UNSIGNED` | nullable, no default | Informational only — PRD §17 leaves the actual threshold value and its exception authority unconfirmed |
| `enrolled_count` | `SMALLINT UNSIGNED` | not null, default `0` | Maintained counter, **not writable via the API** — the authoritative count is `enrollment_subjects` rows that occupy a seat |
| `status` | `VARCHAR(255)` | not null | **Provisional** — see `App\Domain\Scheduling\SectionStatus` |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |
|  |  | **unique** `(academic_term_id, subject_id, section_code)` — `sections_unique_code_per_term_subject` | |

## Authorization

## Submitted-plan amendment invariant

`SaveSectionPlan::save()` never reopens, changes, or clears an existing
`submitted` year-level plan. A Program Chair may add a *previously absent*
year-level plan as a new `draft`, after which it follows the ordinary release
and Dean/Executive proposal approval lifecycle. This supports correcting an
omitted cohort without rewriting the submitted years that remain the official
record.

Read/write shape matches `Curriculum` exactly (ADR 0009): `GET /api/v1/sections`
is readable by every role. `Section::scopeVisibleTo()` restricts Executive
Director to published rows only for the master schedule; it restricts Faculty to
their own assigned `professor_id` rows in `published`/`closed` status; Student
and Accounting retain the status-only `published`/`closed` visibility rule,
while remaining planning roles retain every status. `SectionPolicy::view()`
mirrors both the Faculty own-assignment and Executive published-only rules to
prevent direct-ID bypass. `POST`/`PATCH
/api/v1/sections` are gated `role:program_chair`, re-checked by
`SectionPolicy`.

## Conflict detection (FR-SCH-005)

See ADR 0010 for the full rationale. In summary: `SectionConflictDetector`
rejects a section write only when the *same professor* would be double-booked
— same term, at least one shared day (via `ScheduleDayParser`), overlapping
time range. Room conflicts and availability-matching are deliberately not
checked; nothing in the schema evidences either as a hard rule. The check is
skipped when `professor_id`, `schedule_days`, or either time field is absent.

## Seeded data

`database/seeders/SectionSeeder.php` (already existed from the found
scaffold) seeds six published sections across an ongoing term when one exists,
including two sections of the same subject (`CS102-A`/`CS102-B`) to exercise
the unique `(term, subject, section_code)` constraint. With the archive-first
manual seed there is no ongoing term, so this seeder is intentionally a no-op
until Registrar Head opens one. `viability_threshold` is deliberately left
`NULL` for the same PRD §17 reason as the column itself.

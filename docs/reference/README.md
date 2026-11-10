# Reference data

Source spreadsheets supplied by the user during Phase 6 planning, kept here
verbatim (converted from `.xlsx` to `.csv`, no other changes) for provenance.

- `ccs-block-sections-ay2026-2027-1st-sem.csv` — CCS block sections, AY
  2026–2027, 1st semester. 384 subject rows across 13 IT block sections
  (IT 101–IT 109, IT 201–IT 208, IT 301–IT 307, IT 401–IT 407 minus gaps).
- `ccs-block-sections-ay2024-2025-2nd-sem.csv` — CCS block sections, AY
  2024–2025, 2nd semester.

## What was extracted

`database/seeders/CcsSubjectSeeder.php` transcribes only `code`, `title`, and
`units` for the 88 distinct subjects across both files — the real CCS subject
catalog, seeded additively alongside (not replacing) the existing synthetic
catalog in `SubjectSeeder.php`. Schedule, room, faculty, modality, and Google
Classroom columns are out of scope for Phase 6.

## Known data-quality issues in the source files

- **13 rows have a SCHED ID (a 5-digit number like `33191`) in the UNITS
  column instead of a unit count** — a column-alignment artifact, not a real
  value. `CcsSubjectSeeder` resolves the correct unit count per subject code
  by majority value across all its rows, discarding these outliers.
- A handful of rows have a blank units cell for the same reason.
- `"Mon Thu"` (space-separated multi-day) in the DAY column would silently
  truncate to Monday-only under the existing
  `App\Domain\Scheduling\ScheduleDayParser`, which expects concatenated
  tokens like `"MWF"`. Not currently relevant — Phase 6 does not import
  schedules — but worth knowing before any future phase does.
- Leadership subjects (`LEAD 1`–`LEAD 8`) are genuinely **1.5 units**, which
  is why `subjects.units` and `enrollments.total_units` were widened from
  integer to `decimal(_,1)` columns in Phase 6.
- Subject codes are transcribed exactly as the spreadsheets spell them,
  including real inconsistencies such as `"LEAD 1"` (with a space) vs.
  `"LEAD8"` (without) — not normalized, since the goal is a faithful catalog.

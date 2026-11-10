# Seeded Development Identities

**Scope:** Local development and automated tests only.
**Sources:** `backend/database/seeders/RoleUserSeeder.php` and
`backend/database/seeders/CollegeProgramChairSeeder.php`.

These are **database fixtures**, not production accounts. They exist so every
PRD role can be exercised against real Sanctum authentication during
development. Every synthetic login uses the shared password `password`.

## These are now the only sign-in credentials

A second, UI-only set once existed in `docs/testing/DEMO_CREDENTIALS.md`: nine
hardcoded frontend fixtures with a committed shared password, used when the SPA
ran in a `demo` auth mode without a backend. **Both the file and that auth mode
were deleted in the Next.js migration** (roadmap Phase 3, ADR 0013) — they
predated real Sanctum authentication, and porting their environment guard to
Next.js risked making a committed password valid in a production build.

The identities below are the primary role-based sign-ins.

## The ten identities

One per supported role. Names and emails are deterministic; reseeding updates these
rows in place rather than creating duplicates.

| Role | Name | Email |
|---|---|---|
| `student` | Seed Student | `student.seed@grc.test` |
| `admission_staff` | Seed Admission Staff | `admission.seed@grc.test` |
| `faculty` | Testing Faculty | `faculty.seed@grc.test` |
| `program_chair` | Seed Program Chair | `chair.seed@grc.test` |
| `dean` | Seed Dean | `dean.seed@grc.test` |
| `executive_director` | Seed Executive Director | `executive.seed@grc.test` |
| `registrar_head` | Seed Registrar Head | `registrar-head.seed@grc.test` |
| `registrar_staff` | Seed Registrar Staff | `registrar-staff.seed@grc.test` |
| `accounting_staff` | Seed Accounting Staff | `accounting.seed@grc.test` |
| `it_admin` | IT Control | `it.control@grc.test` |

All are created with status `active`. Emails use the reserved `.test` TLD
(RFC 2606), so they can never resolve to a real mailbox.

## Queue kiosk device fixture

**Source:** `backend/database/seeders/QueueKioskSeeder.php`.

The following development-only device identity is intentionally separate from
the human-role identities above. It is allowed solely because the seeder refuses
to run outside Laravel's `local` and `testing` environments:

| Role | Email | Password | Surface |
|---|---|---|---|
| `queue_kiosk` | `queue@grc.com` | `password` | `/queue` only |

Rotate this password immediately after seeding through the Accounting **Queue
kiosk access** workspace. It is a deliberately weak local/testing fixture, not
a production credential, and must not be reused for a personal account.

The normal portal `/login` rejects the `queue_kiosk` role, revokes the
just-issued wrong-surface token, and directs the operator to `/queue`. The
device route keeps its own kiosk session; a Student signs in there separately
to claim their own ticket. Do not use this fixture on the normal portal or in
production.

## College-specific Program Chair identities

`CollegeProgramChairSeeder` creates one additional active Program Chair for
each supported college. These accounts are additive: the generic
`chair.seed@grc.test` identity above remains available.

| College | Name | Email | Password |
|---|---|---|---|
| College of Computer Studies (CCS) | Seed Program Chair — College of Computer Studies | `chair.ccs@grc.test` | `password` |
| College of Education (COE) | Seed Program Chair — College of Education | `chair.coe@grc.test` | `password` |
| College of Accountancy (COA) | Seed Program Chair — College of Accountancy | `chair.coa@grc.test` | `password` |
| College of Business Administration and Entrepreneurship (CBAE) | Seed Program Chair — College of Business Administration and Entrepreneurship | `chair.cbae@grc.test` | `password` |

All four use the same local/testing-only shared password: `password`.

## The password

All synthetic identities use:

```text
password
```

This deliberately guessable credential is permitted only because
`RoleUserSeeder`, `CollegeProgramChairSeeder`, and `DemoEnrollmentSeeder`
refuse to run outside Laravel's `local` and `testing` environments. Laravel
hashes the value before storing it; the database never contains the plain-text
password.

## Additional student scenarios

**Source:** `backend/database/seeders/DemoEnrollmentSeeder.php`. Eight
student logins with real locked grade history spanning year 1 semester 1
through year 4 semester 2, on the real BSIT program's active (2024-2029)
curriculum. `enrollment_category`
(Regular/Irregular) is **never hard-coded** here — every seed run writes the
locked grades, then runs the real `ClassifyEnrollmentStanding` against
them, so the category shown below is the classifier's own verdict, not an
assumption. None of the eight carry an enrollment for the current term —
every one is free to submit a real, fresh enrollment through the UI/API.
All legacy `student*.seed@grc.test` QA student profiles are marked
`is_demo_account=true`; the 3,210 structured-roster profiles are explicitly
marked `false` and are the only participants in the local attrition scenario.

| # | Email | Year | Category | Completed semesters | What makes them Irregular |
|---|---|---|---|---|---|
| 0001 | `student.seed@grc.test` | 1st | Regular | 1 | — |
| 0002 | `student2.seed@grc.test` | 2nd | Regular | 3 | — |
| 0003 | `student3.seed@grc.test` | 3rd | Regular | 5 | — |
| 0004 | `student4.seed@grc.test` | 4th | Regular | 7 | — |
| 0005 | `student5.seed@grc.test` | 2nd | Irregular | 3 | `5.00` (Failed) on MATHWRLD |
| 0006 | `student6.seed@grc.test` | 2nd | Irregular | 3 | `INC` (Incomplete) on PROG1 |
| 0007 | `student7.seed@grc.test` | 3rd | Irregular | 5 | `NC` (Not Complete) on LEAD 5 |
| 0008 | `student8.seed@grc.test` | 4th | Irregular | 7 | SPI never graded (missing required subject) |

All eight use the shared password `password`.

### Two curriculum-versioning demo students

Separate from the eight above, and unrelated to the grade-history roster:
two logins on the REAL `BSIT` program's curriculum versions, demonstrating
that a student's entry year decides which curriculum version they follow
(`App\Domain\Curriculum\CurriculumVersion::resolveForEntryYear()`). Neither
carries grade history or a block enrollment — they exist only to make
curriculum-version resolution visible end to end.

| Email | Entry year | Curriculum version | Year level |
|---|---|---|---|
| `student.oldcurriculum.seed@grc.test` | 2023 | 2018-2023 (archived — last batch) | 4th |
| `student.newcurriculum.seed@grc.test` | 2024 | 2024-2029 (active) | 3rd |

Both use the shared password `password`.

A year-Y student has `(Y-1)*2 + 1` completed curriculum ordinals
(`SemesterSlot::ordinal()`), always right-aligned to the most recent closed
term — a year-4 student's 7 completed ordinals use all 7 non-ongoing terms
1:1; shorter histories use however many of the most recent terms they need.
Every locked grade is recorded with `section_id = null` (no owning section
for historical results).

## Connected professor identities (live, from the source Excel)

**Source:** `backend/database/seeders/DemoEnrollmentSeeder.php`. Every block
section generated for the four regular seeded students (see above) used to be
owned by the single `faculty.seed@grc.test` account, then later a fixed
10-entry invented roster. Neither exists anymore: `seedProfessors()` now
creates one real `faculty` account per distinct `reference_professor_name`
found across years 1–4's current-term subjects (`BLOCK_SUBJECTS_BY_YEAR`) in
the real BSIT curriculum's own schedule/faculty reference data — the same
names that appear in `curriculum-2024-2029-schedule-references.csv`. This
only runs once a `semester_ongoing` term exists, which a bare clean seed no
longer leaves behind (see "Seeded programs, curricula, and academic terms"
below) — after the Registrar completes the archive-and-open-next workflow
(or a test seeds one directly), it produces **23** of these (this number is
a property of the source Excel, not a designed constant, and will drift if
that data changes). A subject whose representative block has no named
professor in the source Excel gets no Faculty account at all, and its
generated sections keep `professor_id` null — no name is ever invented.

Every email follows `prof.<slug of the name>@grc.test` (e.g. `MR. SALAZAR` →
`prof.mr-salazar@grc.test`, `COACH JUDE SALONGA` →
`prof.coach-jude-salonga@grc.test`). A few, to log in against directly:

| Reference name | Example subject | Email |
|---|---|---|
| MR. SALAZAR | PATHFIT2 (year 1) *and* PATHFIT4 (year 2) | `prof.mr-salazar@grc.test` |
| MS. GALLEN | CONWRLD (year 1) | `prof.ms-gallen@grc.test` |
| COACH MARIEL | LEAD2 (year 1) | `prof.coach-mariel@grc.test` |
| MS. PUNZALAN | DSTRUCTL (year 2) | `prof.ms-punzalan@grc.test` |
| MR. CACHO | ADVMOBL (year 3) | `prof.mr-cacho@grc.test` |
| COACH JUVELYN | LEAD6 (year 3) *and* LEAD8 (year 4) | `prof.coach-juvelyn@grc.test` |

`MR. SALAZAR` and `COACH JUVELYN` above are deliberate examples of a
professor teaching more than one of these subjects: `faculty_subject_preferences`
has a `(professor_id, academic_term_id, rank)` unique constraint, so their
first subject encountered gets rank 1 and each additional one gets the next
rank (2, 3, ...) — never a second rank-1 row for the same professor.

All 23 use the shared password `password`, role `faculty`, college CCS. Each
one also has a declared weekday (Mon–Fri, 08:00–17:00) `FacultyAvailability`
window covering every `BLOCK_SCHEDULES` slot their sections meet at, and a
`FacultySubjectPreference` for every subject they own — real Faculty Input
rows, not just a `professor_id` pointer, so logging in as any of them shows a
genuine Teaching Schedule, Class Roster, and Grade Submission workspace. The
full current list can always be enumerated directly:

```php
CurriculumSubject::query()
    ->where('curriculum_id', Curriculum::where('program_id', Program::where('code', 'BSIT')->sole()->id)->where('status', 'active')->sole()->id)
    ->whereIn('year_level', [1, 2, 3, 4])
    ->where('semester', '2nd')
    ->whereNotNull('reference_professor_name')
    ->pluck('reference_professor_name')
    ->unique();
```

## 4 sample faculty identities (one per college)

**Source:** `backend/database/seeders/ProgramChairScheduleSampleSeeder.php`.
Separate from both the connected professors above and the CCS catalog
faculty below: one Faculty account per college, created so each college's
Program Chair workspace has a faculty member to schedule against. These are
the accounts that back the mixed Dean/Executive approval fixtures. Like the
connected professors above, this seeder picks the current term with
`whereNotIn('status', ['archived', 'semester_closed'])` and no-ops if none
matches — a bare clean seed's only term is `semester_closed`, so it produces
none of these four until the Registrar opens the next term.

| College | Name | Email |
|---|---|---|
| CCS | Sample Faculty — CCS | `faculty.sample.ccs@grc.test` |
| COE | Sample Faculty — COE | `faculty.sample.coe@grc.test` |
| COA | Sample Faculty — COA | `faculty.sample.coa@grc.test` |
| CBAE | Sample Faculty — CBAE | `faculty.sample.cbae@grc.test` |

All four use the shared password `password` and role `faculty`.

### The full faculty headcount, reconciled

**234** `faculty` rows is the sum of four separate seeders rather than one
roster — worth knowing before assuming a count is wrong — but only once an
ongoing term exists. A bare clean seed produces **207**: the `prof.*` and
`faculty.sample.*` rows both depend on a `semester_ongoing` term (see the two
sections above), which a clean seed no longer leaves behind. After the
Registrar opens the next term (or a test/dev script seeds one directly), the
remaining 27 appear and the total reaches 234:

| Source | Count on a bare clean seed | Count once a term is ongoing |
|---|---|---|
| `CatalogFacultySeeder` (real CCS/COE/COA/CBAE CSV catalog) | 206 | 206 |
| `DemoEnrollmentSeeder` (the connected `prof.*` professors above) | 0 | 23 |
| `ProgramChairScheduleSampleSeeder` (the 4 `faculty.sample.*` above) | 0 | 4 |
| `RoleUserSeeder` (`faculty.seed@grc.test`) | 1 | 1 |
| **Total** | **207** | **234** |

### Why not the whole 211-professor catalog

`CatalogFacultySeeder` seeds 206 real-named faculty from the CCS CSV catalog,
but only a handful of them declare any availability — most render "No
availability declared" in the Faculty Assignment workspace, since that CSV
carries no availability data. The connected professor identities above are
the ones this project actually exercises end to end for teaching-side
workflows.

## Running the seeder

```powershell
cd backend
php artisan db:seed --class=RoleUserSeeder
```

Seed the four college-specific Program Chair accounts directly:

```powershell
php artisan db:seed --class=CollegeProgramChairSeeder
```

Or seed everything registered in `DatabaseSeeder`:

```powershell
php artisan db:seed
```

## Safety guarantees

The identity seeders are covered by
`backend/tests/Feature/Database/RoleUserSeederTest.php` and
`backend/tests/Feature/Database/CollegeProgramChairSeederTest.php`, which
assert that they:

- create exactly one active user per role, with unique `@grc.test` emails;
- store only a bcrypt hash, never the plain-text password;
- give every synthetic login the shared development password `password`;
- are idempotent — reseeding updates the same rows, creating no duplicates;
- never delete unrelated users;
- throw rather than seeding anything outside the `local` and `testing`
  environments, even when invoked programmatically.

Laravel's own `db:seed` production confirmation prompt provides a second,
independent layer of protection at the command level.

## Seeded programs, curricula, and academic terms

**Source:** `backend/database/seeders/ProgramSeeder.php`,
`GrcSubjectCatalogSeeder.php`, `GrcCurriculumSeeder.php`,
`GrcPrerequisiteSeeder.php`, and `AcademicTermSeeder.php`. Same
`local`/`testing`-only guarantee as above.

`ProgramSeeder` seeds the **real** 12-program GRC catalog — the only
programs the supplied 2024-2029 curriculum schedules cover, across the four
currently supported colleges — plus one collegeless test fixture (`BSCRIM`).
Covered by `backend/tests/Feature/Database/ReferenceDataSeederTest.php`,
`GrcSubjectCatalogSeederTest.php`, `GrcCurriculumSeederTest.php`, and
`GrcPrerequisiteSeederTest.php`.

| Program code | Name | Status | College |
|---|---|---|---|
| `BSIT` | BS Information Technology | `active` | CCS |
| `BEED` | Bachelor of Elementary Education | `active` | COE |
| `BSED-FIL` | BSEd major in Filipino | `active` | COE |
| `BSED-ENG` | BSEd major in English | `active` | COE |
| `BSED-SOCSCI` | BSEd major in Social Studies | `active` | COE |
| `BSED-VAL` | BSEd major in Values Education | `active` | COE |
| `TCP` | Teacher Certificate Program | `active` | COE |
| `BSBA-FM` | BSBA major in Financial Management | `active` | CBAE |
| `BSENTREP` | BS Entrepreneurship | `active` | CBAE |
| `BSBA-MM` | BSBA major in Marketing Management | `active` | CBAE |
| `BSBA-HRM` | BSBA major in Human Resource Management | `active` | CBAE |
| `BSA` | BS Accountancy | `active` | COA |
| `BSCRIM` | BS Criminology | `inactive` | — |

Each of the 12 real programs gets **3 curriculum versions** (36 total): the
active `2024-2029` (seeded exactly as extracted from GRC's real block-section
schedules — see `backend/database/seeders/data/extract-curriculum-placements.py`),
and two archived versions, `2018-2023` and `2012-2017`, copying that map with
a small illustrative per-program diff (`GrcCurriculumSeeder::ARCHIVED_VARIATIONS`
— **not real historical GRC data**, since none was supplied; it exists only
to prove the versioning mechanism works). `App\Domain\Curriculum\
CurriculumVersion::resolveForEntryYear()` picks the correct version for a
student's entry year — see the two demo students under "Additional student
scenarios" above for it working end to end.

| School year | Semester | Status |
|---|---|---|
| `2023-2024` | `1st` | `archived` |
| `2023-2024` | `2nd` | `archived` |
| `2024-2025` | `1st` | `archived` |
| `2024-2025` | `2nd` | `archived` |
| `2025-2026` | `1st` | `archived` |
| `2025-2026` | `2nd` | `archived` |
| `2026-2027` | `1st` | `semester_closed` — **the current term.** Just concluded and genuinely needing the Registrar to archive it before the next school year/semester can be opened. |

**Further amendment, reverting to the archive-and-open-next starting
state:** a clean seed's current term is `2026-2027` 1st semester again —
closed, tracked as current, with no 2nd semester row at all — instead of a
pre-seeded `2026-2027` 2nd already `semester_ongoing` with published
sections. This trades away the "test enrolling right now" convenience the
prior amendment (to ADR 0018/0020) added, in favor of exercising the literal
Registrar workflow end to end: Archive current semester → name the next
school year/semester → Draft → Program Chair publishes a schedule →
Registrar opens enrollment. `SectionSeeder` and
`DemoEnrollmentSeeder::seedRegularBlocks()` both already guard on a
`semester_ongoing` term existing and no-op cleanly when one doesn't, so
neither needed a code change for this reversion — a Registrar Head reaches
an enrollable term through the ordinary archive-and-create-next flow at any
time.

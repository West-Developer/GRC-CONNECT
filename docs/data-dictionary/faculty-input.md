# Faculty Input Data Dictionary

**Database:** MariaDB 10.4.32 (`grc_enrollment` / `grc_enrollment_test`), per
ADR 0007.

Migrations: `backend/database/migrations/2026_07_27_00000{6,7}_*.php`
(schema landed in the schema-foundation task; API layer added in this slice).

## `faculty_availabilities`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `professor_id` | `BIGINT UNSIGNED` | not null, FK → `users.id`, `CASCADE` on delete | |
| `academic_term_id` | `BIGINT UNSIGNED` | not null, FK → `academic_terms.id`, `CASCADE` on delete | |
| `day_of_week` | `TINYINT UNSIGNED` | not null | ISO-8601 numbering (1 = Monday … 7 = Sunday) |
| `starts_at_time`, `ends_at_time` | `TIME` | not null | |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |
|  |  | **unique** `(professor_id, academic_term_id, day_of_week, starts_at_time)` — `faculty_availability_unique_slot` | A professor cannot declare the same slot twice |

## `faculty_subject_preferences`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `professor_id` | `BIGINT UNSIGNED` | not null, FK → `users.id`, `CASCADE` on delete | |
| `academic_term_id` | `BIGINT UNSIGNED` | not null, FK → `academic_terms.id`, `CASCADE` on delete | |
| `subject_id` | `BIGINT UNSIGNED` | not null, FK → `subjects.id`, `RESTRICT` on delete | |
| `rank` | `TINYINT UNSIGNED` | not null | |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |
|  |  | **unique** `(professor_id, academic_term_id, subject_id)` — `faculty_preference_unique_subject` | A professor cannot rank the same subject twice in one term |
|  |  | **unique** `(professor_id, academic_term_id, rank)` — `faculty_preference_unique_rank` | A professor cannot reuse a rank position in one term |

## Authorization — own-record, not status-based

Every prior slice's `scopeVisibleTo()` filters rows by a status enum's
`isVisibleToLearners()` predicate. Neither table here has a status column;
the visibility question instead is "whose row is this" —
`FacultyAvailability`/`FacultySubjectPreference::scopeVisibleTo()` restrict
learner-scoped roles (`UserRole::isLearnerScoped()`, in practice only
`Faculty` ever holds rows here) to `WHERE professor_id = $user->id`; planning
roles (`!isLearnerScoped()`) see every professor's rows unfiltered, since
planning a schedule needs the full picture.

`GET /api/v1/faculty-availabilities` and
`GET /api/v1/faculty-subject-preferences` are readable by every authenticated
role. `POST`/`PATCH`/`DELETE` on both are gated `role:faculty` at the route,
re-checked by `FacultyAvailabilityPolicy`/`FacultySubjectPreferencePolicy`
(`update`/`delete` require `professor_id === $user->id`, not just the
`faculty` role — one professor cannot modify another's row even though both
hold the same role). `professor_id` is never accepted from the request body;
the controller forces it to the authenticated user's ID on create.

## Validation

The two composite unique constraints on `faculty_subject_preferences` are
enforced pre-flight via `Illuminate\Validation\Rule::unique()->where()`
(scoped to the authenticated professor and the submitted term,
`->ignore()`-ing the current row on update) rather than left to surface as a
raw SQL constraint violation — same outcome as the curriculum catalog's
`PrerequisiteCycleDetector` `withValidator()` hook (a clean 422
`VALIDATION_FAILED`), simpler mechanism because the underlying rule is a
plain uniqueness check rather than graph logic.

## Seeded data

`DemoEnrollmentSeeder` seeds both tables for its 10 connected demo
professors (see `docs/testing/SEEDED_IDENTITIES.md` § "10 connected
professor identities"): a `FacultyAvailability` row per weekday
(Mon–Fri, 08:00–17:00) and a single rank-1 `FacultySubjectPreference` for
each professor's own subject. `ProgramChairScheduleSampleSeeder` also
seeds one `FacultyAvailability` row and ranked `FacultySubjectPreference`
rows per college for its own separate "Sample Faculty" fixtures. Every
other faculty account from `CatalogFacultySeeder`'s 206-row CSV import
declares no availability at all — that seeder only writes
`FacultySubjectPreference` rows from the CSV's subject column, so most of
that roster still renders "No availability declared" in the Faculty
Assignment workspace.

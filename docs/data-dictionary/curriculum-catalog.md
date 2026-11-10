# Curriculum Catalog Data Dictionary

**Database:** MariaDB 10.4.32 (`grc_enrollment` / `grc_enrollment_test`), per
ADR 0007. Same charset/collation/engine as the identity foundation tables.

Migrations: `backend/database/migrations/2026_07_27_00000{1,2,3,4}_*.php`.
First slice in this codebase to use foreign keys — see ADR 0009 for the
delete-behavior rationale.

## `subjects`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `code` | `VARCHAR(255)` | not null, **unique** | e.g. `CS101` |
| `title` | `VARCHAR(255)` | not null | |
| `units` | `TINYINT UNSIGNED` | not null | Credit units |
| `paired_subject_id` | `BIGINT UNSIGNED` | nullable, FK → `subjects.id`, `SET NULL` on delete | This subject's lecture/laboratory companion, symmetric (both rows point at each other). A subject's LEC and LAB components are institutional policy always taken together — `BuildEligibleSubjectPool`/`EligibleSubjectTable`/`StoreEnrollmentRequest` all read this. Backfilled once (migration `2026_08_27_000002_add_paired_subject_id_to_subjects.php`) by matching a `" LEC"`-titled subject to the same-college subject whose code is its own plus a trailing `"L"` and whose own title ends in `" LAB"` — not set for a lab-only course with no matching lecture code (e.g. `ITPLUS3`/`ITPLUS4`). |
| `status` | `VARCHAR(255)` | not null | Application-backed string. **Provisional** `active`/`inactive` — see `App\Domain\Curriculum\SubjectStatus`; institutional vocabulary unconfirmed (PRD §17) |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

## `curricula`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `program_id` | `BIGINT UNSIGNED` | not null, FK → `programs.id`, `RESTRICT` on delete | A program with a curriculum cannot be deleted |
| `name` | `VARCHAR(255)` | not null | |
| `effective_school_year` | `VARCHAR(255)` | not null | Canonical form e.g. `2026-2027` |
| `status` | `VARCHAR(255)` | not null | **Provisional** `draft`/`active`/`archived` — see `App\Domain\Curriculum\CurriculumStatus`; institutional vocabulary unconfirmed (PRD §17) |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

## `curriculum_subjects`

A subject's placement within one curriculum — which year/semester it is
taken in.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `curriculum_id` | `BIGINT UNSIGNED` | not null, FK → `curricula.id`, `CASCADE` on delete | Placements are owned by their curriculum |
| `subject_id` | `BIGINT UNSIGNED` | not null, FK → `subjects.id`, `RESTRICT` on delete | A subject placed in a curriculum cannot be deleted |
| `year_level` | `TINYINT UNSIGNED` | not null | |
| `semester` | `VARCHAR(255)` | not null | |
| `is_required` | `BOOLEAN` | not null | |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |
|  |  | **unique** `(curriculum_id, subject_id)` | A subject may not appear twice in one curriculum |

## `subject_prerequisites`

What a placement requires beforehand. `prerequisite_subject_id` references
the global `subjects` catalog, not another placement — the same subject can
carry a different prerequisite requirement in a different curriculum,
because the owning side is `curriculum_subject_id`.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `curriculum_subject_id` | `BIGINT UNSIGNED` | not null, FK → `curriculum_subjects.id`, `CASCADE` on delete | Prerequisite rows are owned by the placement |
| `prerequisite_subject_id` | `BIGINT UNSIGNED` | not null, FK → `subjects.id`, `RESTRICT` on delete | A subject that is someone's prerequisite cannot be deleted |
| `minimum_grade` | `VARCHAR(255)` | not null | Institutional grading policy is unconfirmed (PRD §17); stored as an opaque string, not validated against a scale |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |
|  |  | **unique** `(curriculum_subject_id, prerequisite_subject_id)` | Named `subject_prereq_unique_mapping` — no duplicate mapping |

## Prerequisite cycle rejection (FR-SCH-002)

Enforced at the application layer, not the database: `App\Domain\Curriculum\PrerequisiteCycleDetector`
runs a DFS over the `subject_id → prerequisite_subject_id` edges in a
submitted curriculum payload and rejects the request (422
`VALIDATION_FAILED`) if a direct or transitive cycle would result. See ADR
0009 for why this is a pure, persistence-independent check running against
the full submitted payload rather than incremental DB diffs.

## Authorization

`GET /api/v1/subjects` and `GET /api/v1/curricula` are readable by every
role; `scopeVisibleTo()` on `Subject`/`Curriculum` filters rows for
learner-scoped roles the same way as `Program`/`AcademicTerm` (ADR 0008).
`POST`/`PATCH /api/v1/curricula` are restricted to the Program Chair role —
the first production route gated by the `role` middleware — re-checked by
`CurriculumPolicy`. See ADR 0009.

## Reversibility

Rollback drops the four tables in reverse migration order
(`subject_prerequisites` → `curriculum_subjects` → `curricula` → `subjects`),
which also satisfies the FK dependency order. Verified by
`CurriculumCatalogMigrationTest::test_migrations_are_fully_reversible`.

## Seeded data

`database/seeders/SubjectSeeder.php` and `CurriculumSeeder.php` — added after
this slice's initial merge, alongside the faculty-input/section-planning/
approval-workflow schema foundation. `SubjectSeeder` seeds a small coherent
computing-program catalog; `CurriculumSeeder` builds a genuine multi-step
prerequisite chain on top of it (`CS102 → CS201 → CS202 → CS301`) plus a
still-`draft` curriculum, so learner-visibility filtering has something real
to prove. `local`/`testing` environments only, idempotent, `updateOrCreate`
keyed on natural keys.

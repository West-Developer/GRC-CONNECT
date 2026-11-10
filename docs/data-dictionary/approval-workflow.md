# Approval Workflow Data Dictionary

**Database:** MariaDB 10.4.32 (`grc_enrollment` / `grc_enrollment_test`), per
ADR 0007.

Migration: `backend/database/migrations/2026_07_27_000009_create_schedule_proposals_table.php`
(schema landed in the schema-foundation task; API layer added in this
slice — see ADR 0011 for the role-per-transition and publish/section
linkage design).

## `schedule_proposals`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `academic_term_id` | `BIGINT UNSIGNED` | not null, FK → `academic_terms.id`, `RESTRICT` on delete | At most one non-`closed` proposal per term, enforced in `StoreScheduleProposalRequest`, not a DB constraint (same reasoning as `enrollments`' generated column — see ADR 0011) |
| `submitted_by` | `BIGINT UNSIGNED` | not null, FK → `users.id`, `RESTRICT` on delete | Always the Program Chair who submitted it |
| `status` | `VARCHAR(255)` | not null | **Authoritative** PRD §4.1 lifecycle — see `App\Domain\Scheduling\ScheduleProposalStatus` |
| `decided_by` | `BIGINT UNSIGNED` | nullable, FK → `users.id`, `SET NULL` on delete | Whoever performed the most recent transition |
| `decided_at` | `TIMESTAMP` | nullable | |
| `decision_reason` | `TEXT` | nullable | Required by validation only when returning to `draft` |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

## Authorization — one route, six transitions, four abilities

Unlike every other write-gated resource in this API, `PATCH
/api/v1/schedule-proposals/{scheduleProposal}` carries no `role:` middleware
— a single `action` field in the request body drives six different
transitions, each needing a different role:

| Action | Requires current status | Actor | Policy ability |
|---|---|---|---|
| `dean_approve` | `draft` | Dean | `approveAsDean` |
| `dean_return` | `dean_approved` | Dean | `approveAsDean` |
| `executive_approve` | `dean_approved` | Executive Director | `approveAsExecutive` |
| `executive_return` | `executive_approved` | Executive Director | `approveAsExecutive` |
| `publish` | `executive_approved` | Executive Director | `publish` |
| `close` | `published` | Registrar Head | `close` |

`decision_reason` is required exactly when the action is `dean_return` or
`executive_return`. `GET /api/v1/schedule-proposals` and `POST` (submission,
`role:program_chair`) follow the established read/write shape otherwise —
reads filtered by `ScheduleProposal::scopeVisibleTo()`
(`published`/`closed` visible to learner-scoped roles, everything visible to
planning roles).

## The publish → sections linkage

`App\Actions\Scheduling\TransitionScheduleProposal` performs every
transition; only `publish` has a side effect beyond the proposal row itself:
in the same `DB::transaction()`, every `Section` in the proposal's
`academic_term_id` with `status = 'planned'` flips to `published`. There is
no foreign key between the two tables — this is a deliberate, minimal choice
over adding one now; see ADR 0011.

## Seeded data

None. No acceptance criterion required seeded fixtures for this sub-project;
tests create records directly.

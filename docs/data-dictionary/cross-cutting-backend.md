# Cross-Cutting Backend Data Dictionary

**Database:** MariaDB 10.4.32 (`grc_enrollment` /
`grc_enrollment_test`), per ADR 0007.

**Migrations:**
`backend/database/migrations/2026_07_29_000001_create_audit_logs_table.php`
through
`2026_07_29_000005_create_attrition_predictions_table.php`.

## Scope and delivery boundary

Phase 4 adds operational audit history, private in-app notifications, and the
storage substrate needed by the future machine-learning phase. The analytical
tables are **schema-only** in Phase 4: no prediction runner, prediction API,
student attrition view, automated decision, or machine-learning frontend is
included.

All future analytical outputs are advisory. They cannot directly create,
resize, dissolve, publish, approve, reject, or close an operational record.
Machine-learning implementation remains the final roadmap phase after the
functional enrollment system.

## `audit_logs`

Append-only operational history for successful domain mutations and
Registrar Head audit-list access.

| Column | Type | Constraints | Meaning |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | Audit event identifier |
| `actor_user_id` | `BIGINT UNSIGNED` | not null, FK → `users.id`, `RESTRICT` on delete | Authenticated user who performed the event |
| `action` | `VARCHAR(100)` | not null | Stable action from `App\Domain\Audit\AuditAction` |
| `auditable_type` | `VARCHAR(100)` | not null | Stable entity type from `App\Domain\Audit\AuditableType` |
| `auditable_id` | `BIGINT UNSIGNED` | nullable | Entity identifier; deliberately not a polymorphic FK |
| `before_values` | `JSON` | nullable | Approved safe state before the mutation |
| `after_values` | `JSON` | nullable | Approved safe state after the mutation |
| `reason` | `TEXT` | nullable | Decision reason when the operation carries one |
| `request_id` | `VARCHAR(128)` | not null | Validated or generated HTTP correlation ID |
| `ip_address` | `VARCHAR(45)` | nullable | Trusted Laravel client IPv4/IPv6 text |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | Standard Laravel timestamps |

Indexes:

- `(auditable_type, auditable_id, created_at)` for entity history;
- `(actor_user_id, created_at)` for actor history;
- `(action, created_at)` for action history;
- `request_id`; and
- `created_at`.

Delete behavior preserves accountability: a referenced actor cannot be
deleted while their audit rows exist. `auditable_id` is intentionally not a
foreign key, so history survives deletion of the audited entity.

`AuditLog` rejects application-level update and delete model events with a
`LogicException`; the API exposes no mutation route. Audit records are created
inside the same database transaction as the domain write, so audit failure
rolls back that write. Safe-value recording rejects password, token, and
authentication-secret keys.

Phase 4 action values are:

- `curriculum.created`, `curriculum.updated`;
- `faculty_availability.created`, `.updated`, `.deleted`;
- `faculty_subject_preference.created`, `.updated`, `.deleted`;
- `section.created`, `section.updated`, `section.published`;
- `schedule_proposal.created`, `.dean_approved`, `.dean_returned`,
  `.executive_approved`, `.executive_returned`, `.published`, `.closed`;
- `student_profile.provisioned`; and
- `audit_log.list_viewed`.

Auditable types are `curriculum`, `faculty_availability`,
`faculty_subject_preference`, `section`, `schedule_proposal`,
`student_profile`, and `audit_log`.

## `notifications`

Private in-app notices owned by one user.

| Column | Type | Constraints | Meaning |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | Notification identifier |
| `user_id` | `BIGINT UNSIGNED` | not null, FK → `users.id`, `CASCADE` on delete | Recipient |
| `type` | `VARCHAR(100)` | not null | `NotificationType`; currently `schedule_published` |
| `message` | `TEXT` | not null | Reader-facing notification text |
| `read_at` | `TIMESTAMP` | nullable | First read time; null means unread |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | Standard Laravel timestamps |

Indexes:

- `(user_id, created_at)` for the newest-first owned list; and
- `(user_id, read_at, created_at)` for the owned unread list.

Notifications are user-owned and therefore cascade when their user is
deleted. The API never serializes `user_id`; authenticated users list and mark
read only their own rows. Marking read is idempotent and preserves the first
`read_at` timestamp.

Publishing a schedule creates one `schedule_published` notification for:

- the Program Chair who submitted the proposal; and
- every unique, non-null professor assigned to a newly published section.

A person appearing in both groups receives one notification. Publication,
section state changes, audit rows, and notifications share one transaction.

## `prediction_runs`

Versioned execution metadata for a future analytical run.

| Column | Type | Constraints | Meaning |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | Run identifier |
| `type` | `VARCHAR(100)` | not null | `section_demand` or `attrition` |
| `academic_term_id` | `BIGINT UNSIGNED` | nullable, FK → `academic_terms.id`, `RESTRICT` on delete | Optional term scope |
| `model_version` | `VARCHAR(100)` | not null | Version of the future trained model |
| `feature_schema_version` | `VARCHAR(100)` | not null | Version of the reproducible feature contract |
| `status` | `VARCHAR(50)` | not null | `queued`, `running`, `succeeded`, or `failed` |
| `metrics` | `JSON` | nullable | Approved evaluation/operational metrics |
| `error_summary` | `TEXT` | nullable | Safe operational summary, never a stack trace or student feature payload |
| `started_at`, `completed_at` | `TIMESTAMP` | nullable | Run lifecycle timestamps |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | Standard Laravel timestamps |

Indexes:

- `(type, status, created_at)`; and
- `(academic_term_id, type, created_at)`.

The migration stores enum values as strings; application models cast them to
`PredictionType` and `PredictionRunStatus`. Deleting an academic term is
restricted while a scoped run references it.

## `section_demand_forecasts`

Advisory subject demand output from one future prediction run.

| Column | Type | Constraints | Meaning |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | Forecast identifier |
| `prediction_run_id` | `BIGINT UNSIGNED` | not null, FK → `prediction_runs.id`, `RESTRICT` on delete | Producing run |
| `academic_term_id` | `BIGINT UNSIGNED` | not null, FK → `academic_terms.id`, `RESTRICT` on delete | Forecast term |
| `subject_id` | `BIGINT UNSIGNED` | not null, FK → `subjects.id`, `RESTRICT` on delete | Forecast subject |
| `predicted_demand` | `DECIMAL(10,2)` | not null, check `>= 0` | Forecasted student demand |
| `suggested_section_count` | `SMALLINT UNSIGNED` | not null | Advisory count, never an automatic section write |
| `confidence_lower` | `DECIMAL(10,2)` | nullable, check null or `>= 0` | Optional lower uncertainty bound |
| `confidence_upper` | `DECIMAL(10,2)` | nullable, check null or `>= 0` | Optional upper uncertainty bound |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | Standard Laravel timestamps |

The unique key
`(prediction_run_id, academic_term_id, subject_id)` prevents duplicate output
for one run/term/subject. When both confidence bounds exist,
`confidence_upper >= confidence_lower` is enforced. All three foreign keys
restrict deletion to preserve output lineage.

Fixed-point values are cast to decimal strings, not binary floating-point
numbers.

## `attrition_predictions`

Private, schema-only student risk output for a future authorized intervention
workflow.

| Column | Type | Constraints | Meaning |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | Prediction identifier |
| `prediction_run_id` | `BIGINT UNSIGNED` | not null, FK → `prediction_runs.id`, `RESTRICT` on delete | Producing run |
| `student_id` | `BIGINT UNSIGNED` | not null, FK → `student_profiles.id`, `RESTRICT` on delete | Predicted student profile |
| `risk_probability` | `DECIMAL(5,4)` | not null, check `BETWEEN 0.0000 AND 1.0000` | Advisory probability |
| `risk_band` | `VARCHAR(50)` | not null | Version-controlled future model label |
| `explanations` | `JSON` | nullable | Approved plain-language factors; no automatic-action instructions |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | Standard Laravel timestamps |

The unique key `(prediction_run_id, student_id)` prevents duplicate output for
one student in one run. Both foreign keys restrict deletion to preserve
lineage. `risk_probability` is cast to a four-decimal fixed-point string.

There is no Phase 4 route, Resource, Policy, or frontend access for this
table. Phase 9 must define authorized viewers after GRC resolves the
attrition-intervention policy.

## `HISTORICAL DATA` physical mapping

The PRD's `HISTORICAL DATA` store is a logical analytical domain assembled
from authoritative operational records, not a generic duplicated table.

| Historical category | Authoritative physical source |
|---|---|
| Academic-term boundaries | `academic_terms` |
| Planned/published capacity, thresholds, professor, and timetable history | `sections`, `schedule_proposals`, `audit_logs` |
| Faculty constraints and preferences | `faculty_availabilities`, `faculty_subject_preferences`, `audit_logs` |
| Enrollment volume and subject selection | `enrollments`, `enrollment_subjects` after Phase 6 writes them |
| Prerequisite outcomes and final academic results | `academic_grades`, `transferee_credits` after Phases 6–7 write them |
| Payment, queue, and completion timing | `queue_tickets`, `payments`, `enrollment_documents` after Phase 7 |
| Withdrawal labels and reasons | `withdrawal_requests` after Phase 7 |
| Behavioral event and state-change history | `audit_logs` |
| Model execution metadata and evaluation | `prediction_runs` |
| Approved analytical outputs | `section_demand_forecasts`, `attrition_predictions` |

Phase 9 must build reproducible, versioned feature snapshots from those
sources and record the data period, feature schema version, exclusions, and
evaluation metadata. It must not train directly from unrecorded mutable
production queries.

`report_exports` remains a Phase 9 compliance deliverable. No generic
`historical_data` table is created because it would duplicate authoritative
records and obscure lineage.

## Sensitivity, retention, and deletion expectations

GRC has not yet approved the production retention/archive/disposal schedule
(PRD §17), so Phase 4 deliberately defines no duration or automated purge.
The approved policy must later update configuration, operations runbooks,
tests, and this dictionary rather than being invented in code.

| Table | Sensitivity and access | Phase 4 preservation/deletion behavior |
|---|---|---|
| `audit_logs` | Restricted operational history; API access is Registrar Head only | Application-immutable; no mutation route; actor deletion is restricted and audited entity deletion does not erase history |
| `notifications` | Private user-owned content | Owner-scoped API; rows cascade only when their owning synthetic/real user is deleted; no Phase 4 purge job |
| `prediction_runs` | Restricted model governance and operational metadata | Referenced terms and runs use restrictive FKs; no Phase 4 API or deletion workflow |
| `section_demand_forecasts` | Restricted advisory planning output | All lineage FKs restrict deletion; no Phase 4 API or deletion workflow |
| `attrition_predictions` | Highly sensitive student-level advisory output | No Phase 4 Policy, Resource, Controller, route, or frontend; student and run deletion are restricted |

Future retention work must preserve required audit and model lineage, apply
least-privilege access, and never turn attrition output into a public label or
automatic academic/enrollment action.

## Reversibility

Each migration's `down()` drops only its own table. Reverse migration order is
`attrition_predictions`, `section_demand_forecasts`, `prediction_runs`,
`notifications`, then `audit_logs`, satisfying all foreign-key dependencies.

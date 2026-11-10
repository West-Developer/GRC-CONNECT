# Enrollment Records Data Dictionary

**Database:** MariaDB 10.4.32 (`grc_enrollment` / `grc_enrollment_test`), per
ADR 0007. Same charset/collation/engine as every other table in this codebase.

Migrations: `backend/database/migrations/2026_07_27_0000{05,10,11,12,13,14,15,16,17}_*.php`.

**Scope note:** this page originally documented schema landed as verified
groundwork alongside the PRD §5.1 faculty-input/section-planning/
approval-workflow slice, with no API layer yet built. Phases 6–7b have since
landed the full API for every table on this page — see each table's own
**API** note for its routes and OpenAPI tag.

## `student_profiles`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `user_id` | `BIGINT UNSIGNED` | not null, **unique**, FK → `users.id`, `CASCADE` on delete | One profile per user account |
| `student_number` | `VARCHAR(255)` | not null, **unique** | `YYYY-MM-NNNNN`. Assigned by the server from `student_number_sequences` at account creation (ADR 0042), or typed by Admission for a Returnee or Existing Student who already has one. Older rows also hold `HIST-YYYY-NNNNN` and `TEST-…` seed numbers |
| `program_id` | `BIGINT UNSIGNED` | not null, FK → `programs.id`, `RESTRICT` on delete | |
| `curriculum_id` | `BIGINT UNSIGNED` | not null, FK → `curricula.id`, `RESTRICT` on delete | |
| `year_level` | `TINYINT UNSIGNED` | not null | Cast to `integer` on the model |
| `admission_status` | `VARCHAR(255)` | not null | **Provisional** — see `App\Domain\Identity\AdmissionStatus`; institutional vocabulary unconfirmed (PRD §17) |
| `academic_standing` | `VARCHAR(255)` | not null | **Provisional** — see `App\Domain\Identity\AcademicStanding`; derivation rules (honors cutoff, disqualifying grades) unconfirmed (PRD §17) |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

PRD §10.1's "approved contact fields" are deliberately **not** included —
which contact details GRC authorizes storing is unconfirmed; they arrive with
the approved student-provisioning slice.

## `student_number_sequences`

The running counter behind sequential student numbers (ADR 0042). One row per
calendar year (Asia/Manila); `App\Actions\Identity\AssignStudentNumber` locks
the year's row, adds one, and builds `YYYY-MM-NNNNN`. Migration
`2026_10_04_000001_create_student_number_sequences_table.php`.

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `year` | `SMALLINT UNSIGNED` | primary key | The year in the number |
| `last_value` | `INT UNSIGNED` | not null, default `0` | Last 5-digit suffix handed out that year; never above `99999`. The row is created on first use from the highest existing `YYYY-MM-NNNNN` suffix of that year |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

## `enrollments`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `student_id` | `BIGINT UNSIGNED` | not null, FK → `student_profiles.id`, `CASCADE` on delete | |
| `academic_term_id` | `BIGINT UNSIGNED` | not null, FK → `academic_terms.id`, `RESTRICT` on delete | |
| `status` | `VARCHAR(255)` | not null | **Authoritative** PRD §4.2 lifecycle — see `App\Domain\Enrollment\EnrollmentStatus`. `awaiting_first_payment` (2026-10-05) is a non-terminal value: a transferee's submitted enrollment waits there until the Cashier confirms their first payment, and then moves to `pending_registrar_approval`; it needs no schema change because `active_academic_term_id` is `NULL` only for the three terminal values |
| `total_units` | `SMALLINT UNSIGNED` | not null, default `0` | |
| `requires_overload_approval` | `BOOLEAN` | not null, default `false` | FR-ENR-004. Set by `SubmitEnrollment` via `App\Domain\Enrollment\OverloadEvaluator` — see `config('enrollment.max_regular_units')`/`overload_max_units`, both default `null` (no cap enforced, this column always `false`) |
| `submitted_at`, `registrar_decided_at`, `payment_confirmed_at`, `enrolled_at` | `TIMESTAMP` | nullable | |
| `active_academic_term_id` | `BIGINT UNSIGNED GENERATED ALWAYS AS (...) STORED` | nullable | Mirrors `academic_term_id` while `status` is non-terminal; `NULL` on `rejected`/`cancelled`/`withdrawn` |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |
|  |  | **unique** `(student_id, active_academic_term_id)` — `enrollments_unique_active_per_student_term` | Enforces "one active enrollment per student per term" while permitting re-enrollment after a terminal state, because SQL unique indexes ignore `NULL` |

**This generated column is load-bearing — do not refactor it into a plain
column.** The terminal values (`rejected`, `cancelled`, `withdrawn`) are
written as SQL literals in the migration rather than read from
`EnrollmentStatus`, because a migration must stay an immutable snapshot; the
enum's own docblock carries a matching warning that changing its terminal set
requires a new migration to rebuild this column.

**API:** `GET`/`POST /api/v1/enrollments` (Phase 6 — submission, role-scoped
read since Phase 7a Task 1), `PATCH /api/v1/enrollments/{enrollment}` (Phase
7a Task 2 — `registrar_approve`/`registrar_reject`/`void`),
`POST /api/v1/enrollments/{enrollment}/payment` (Phase 7a Task 5 — idempotent
payment confirmation). OpenAPI tag `Enrollment`.

## `enrollment_subjects`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `enrollment_id` | `BIGINT UNSIGNED` | not null, FK → `enrollments.id`, `CASCADE` on delete | |
| `section_id` | `BIGINT UNSIGNED` | not null, FK → `sections.id`, `RESTRICT` on delete | |
| `status` | `VARCHAR(255)` | not null | **Provisional** — see `App\Domain\Enrollment\EnrollmentSubjectStatus` |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |
|  |  | **unique** `(enrollment_id, section_id)` | PRD §5.3: repeated requests must not duplicate seats |

**API:** written only as part of `POST /api/v1/enrollments` (Phase 6); no
dedicated write route of its own, but read via `GET /api/v1/class-rosters`
(Phase 7b Task 4 — Faculty own sections, Registrar Staff and Registrar Head
all). Also embedded in `EnrollmentResource.subjects`. OpenAPI tag
`Class Rosters`.

## `academic_grades`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `student_id` | `BIGINT UNSIGNED` | not null, FK → `student_profiles.id`, `CASCADE` on delete | |
| `subject_id` | `BIGINT UNSIGNED` | not null, FK → `subjects.id`, `RESTRICT` on delete | |
| `section_id` | `BIGINT UNSIGNED` | nullable, FK → `sections.id`, `SET NULL` on delete | Nullable so transferred/historical records are storable without an owning section |
| `academic_term_id` | `BIGINT UNSIGNED` | not null, FK → `academic_terms.id`, `RESTRICT` on delete | |
| `final_grade` | `DECIMAL(5,2)` | nullable | **Not cast to float on the model** — carried as the exact string MySQL returns; grading scale unconfirmed (PRD §17) |
| `remarks` | `TEXT` | nullable | Carries special marks until that vocabulary is confirmed |
| `status` | `VARCHAR(255)` | not null | **Authoritative** PRD §4.3 lifecycle (`draft`→`submitted`→`locked`) — see `App\Domain\Academic\GradeStatus` |
| `encoded_by` | `BIGINT UNSIGNED` | not null, FK → `users.id`, `RESTRICT` on delete | |
| `submitted_at`, `locked_at` | `TIMESTAMP` | nullable | |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |
|  |  | **unique** `(student_id, subject_id, academic_term_id)` — `academic_grades_unique_student_subject_term` | |

**API (Phase 7a Task 3):** `GET`/`POST`/`PATCH /api/v1/academic-grades`.
Role-scoped read (Student own, Faculty own sections, Registrar Head and
Registrar Staff all — the latter widened in Phase 7b Task 3); `POST` is
Faculty-only; `PATCH` serves a plain content edit (draft only) or
`action: submit`/`lock`. OpenAPI tag `Academic Records`.

## `queue_tickets`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `enrollment_id` | `BIGINT UNSIGNED` | **nullable** (ADR 0043), **unique**, FK → `enrollments.id`, `CASCADE` on delete | One ticket per enrollment; null for a freshman's ticket (below) |
| `student_profile_id` | `BIGINT UNSIGNED` | nullable, **unique**, FK → `student_profiles.id`, `CASCADE` on delete | Set for a freshman who took a queue number at Admission before any enrollment exists. A `CHECK` (`queue_tickets_has_an_owner`) requires `enrollment_id` or `student_profile_id` |
| `public_token_hash` | `VARCHAR(64)` | nullable, **unique** | SHA-256 of the secret in the freshman's emailed live-queue link (ADR 0044). The plain token is never stored |
| `link_sent_at`, `link_failed_at` | `DATETIME` | nullable | Delivery of that email, shown to Admission (`dateTime` so MariaDB adds no implicit `ON UPDATE`) |
| `ticket_number` | `VARCHAR(255)` | not null, **unique with `queue_date`** (not alone — see below) | Resets daily (`Q001`, `Q002`, …), not derived from the enrollment id |
| `queue_date` | `DATE` | not null | |
| `status` | `VARCHAR(255)` | not null | **Provisional** — see `App\Domain\Enrollment\QueueTicketStatus`. `skip` now returns the ticket to `waiting` rather than moving it to `cancelled` |
| `priority` | `VARCHAR(255)` | not null, default `regular` | **Provisional** — see `App\Domain\Enrollment\QueueTicketPriority`. Cashier-set after issuance; no eligibility rule is encoded |
| `served_at` | `TIMESTAMP` | nullable | |
| `served_by` | `BIGINT UNSIGNED` | nullable, FK → `users.id`, `SET NULL` on delete | Never exposed via `QueueTicketResource` — actor identity stays private, same convention as every audited action |
| `requeued_at` | `TIMESTAMP` | nullable | Set to the skip moment; drives ordering ahead of `id` — see below |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

`ticket_number`'s uniqueness moved from a bare column constraint to the
composite `(queue_date, ticket_number)` in
`2026_08_06_000003_reshape_queue_tickets_for_daily_reset_and_priority.php` —
the numbering scheme, reset cadence, and priority rules all remain PRD §17
unconfirmed, but now have a real mechanism rather than none at all.

**API (Phase 7a Task 4, extended in the assessment/fees slice):** `GET
/api/v1/queue-tickets`, `PATCH /api/v1/queue-tickets/{queueTicket}`
(`action: serve`/`complete`/`skip`/`mark_priority`). Accounting Staff only —
the one write pair in this document gated by a coarse
`role:accounting_staff` route middleware rather than Policy alone, since
neither transition has a per-ticket ownership dimension. Calling `serve` on
a new ticket implicitly completes whatever was already `serving` that day
(single-active-serving), enforced in `App\Actions\Enrollment\
TransitionQueueTicket`, not the schema. `App\Models\QueueTicket::position()`
computes how many `waiting` tickets stand ahead of a given one: priority
tickets always precede regular ones, and within a tier tickets are ordered
by `COALESCE(requeued_at, created_at)`, with a same-tick tie splitting a
never-requeued ticket ahead of a requeued one and `id` as the final
fallback (`App\Actions\Enrollment\ListQueueTickets`, the Accounting Staff
queue list, applies the identical rule). `position()` is embedded only in
`EnrollmentResource.queue_ticket.position` for a student's own ticket —
never a full queue listing, for privacy. OpenAPI tag `Payments`.

## `assessments`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `enrollment_id` | `BIGINT UNSIGNED` | not null, **unique**, FK → `enrollments.id`, `CASCADE` on delete | One assessment per enrollment — the idempotency mechanism, same role as `payments.enrollment_id` |
| `total_amount` | `DECIMAL(10,2)` | not null | **Not cast to float on the model** — money stays an exact decimal string. Unlike `payments.amount`, NOT NULL: an assessment with no total is meaningless |
| `currency` | `VARCHAR(255)` | not null | |
| `assessed_at` | `TIMESTAMP` | not null | |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

What an enrollment was assessed to owe (PRD §5.3 process 3.3 "computes the
approved assessment"), computed once by `App\Actions\Billing\AssessEnrollment`
inside `App\Actions\Enrollment\TransitionEnrollment`'s `registrar_approve`
branch — the same transaction that already issues the `queue_tickets` row.
Every rate is read from `config/fees.php`, itself flagged **provisional**
per PRD §17 (payment confirmation fields and supporting reference
requirements remain an open institutional decision). Never recomputed: not
on a later `void` (which orphans the assessment the same way it already
orphans the `QueueTicket`), and not on a post-payment add/drop (no
reassessment/adjustment-billing policy exists yet — a §17 gap, not an
oversight).

**API:** embedded only — no dedicated route. Nested as `assessment` on
`EnrollmentResource`, visible to every role that may view the enrollment at
all (Student own, Registrar Head/Staff all, Accounting Staff
`pending_payment` only). OpenAPI tag `Enrollment`.

## `assessment_items`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `assessment_id` | `BIGINT UNSIGNED` | not null, FK → `assessments.id`, `CASCADE` on delete | |
| `category` | `VARCHAR(255)` | not null | `tuition`, `miscellaneous`, `scholarship_discount` (ADR 0025) or `full_payment_discount` (ADR 0046) — see `App\Domain\Billing\AssessmentItemCategory` |
| `label` | `VARCHAR(255)` | not null | |
| `quantity` | `DECIMAL(6,1)` | nullable | Units on the tuition line — matches `subjects.units`/`enrollments.total_units` precision; **deliberately not an integer**, since Leadership subjects are genuinely 1.5 units. On a discount line it holds the percentage instead (for example `10.0`) |
| `unit_amount` | `DECIMAL(10,2)` | nullable | Per-unit rate, tuition line only |
| `amount` | `DECIMAL(10,2)` | not null | **Not cast to float on the model** |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

One printed line of an `assessments` row. See
`App\Domain\Billing\AssessmentComputation` for how these are computed
(half-up rounding at two decimal places via `bcmath` — also provisional,
GRC has never confirmed a rounding policy).

**`full_payment_discount` (ADR 0046):** a negative `amount` (the discount,
rounded half-up to the cent), the percentage in `quantity`, a label such as
`Full-payment discount (10%)`, and a null `unit_amount`. It is written only
when money is collected, never while the assessment is still being corrected:
by `ConfirmPayment` for the Cashier's Pay in full, inside the confirmation
transaction, or by `SettleEnrollmentFromAdvanceCredit` when the Registrar
approves a pay-first student (freshman or transferee) whose held credit covers
the discounted net. Each write sets `assessments.total_amount` to the net and
is audited as `assessment.full_payment_discount_applied`. An assessment with a
`scholarship_discount` line never gets one, and the line is never edited by
hand. `BuildCorSnapshot` lists it as `fees.full_payment_discount`, with
`fees.total_full_payment_discount`.

**API:** embedded only, as `assessment.items` on `EnrollmentResource`. No
dedicated route. OpenAPI tag `Enrollment`.

## `payments`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `enrollment_id` | `BIGINT UNSIGNED` | not null, **unique**, FK → `enrollments.id`, `CASCADE` on delete | Enforces FR-FIN-009's idempotent-confirmation requirement |
| `confirmed_by` | `BIGINT UNSIGNED` | not null, FK → `users.id`, `RESTRICT` on delete | |
| `external_reference` | `VARCHAR(255)` | nullable | |
| `amount` | `DECIMAL(10,2)` | nullable | **Not cast to float on the model** — money stays an exact decimal string; currency/rounding rule unconfirmed (PRD §17) |
| `confirmed_at` | `TIMESTAMP` | not null | |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

Records that Accounting confirmed an externally received payment
(FR-FIN-007) — **not** a payment gateway integration; holds no cardholder or
bank data.

**API (Phase 7a Task 5, extended in the assessment/fees slice):** written
only by `POST /api/v1/enrollments/{enrollment}/payment` (no `role:`
middleware — `EnrollmentPolicy::confirmPayment` resolves Accounting-only,
only from `pending_payment`). Also `GET /api/v1/payments` (Accounting
Staff's own history, plus Registrar Head oversight — `PaymentPolicy`, no
`role:` middleware, `confirmed_on` date filter) — added because `payments`
rows must remain visible to Accounting even after `ConfirmPayment` moves
the owning enrollment out of `pending_payment` and it disappears from
`Enrollment::scopeVisibleTo`'s accounting view; widening that scope instead
would have handed Accounting visibility into every historical enrollment,
not just its own payment records. Idempotent on `enrollment_id`'s unique
constraint (FR-FIN-009). OpenAPI tag `Payments`.

**Freshman first payment (ADR 0043):** also written by
`POST /api/v1/student-profiles/{id}/freshman-payment` (Accounting Staff),
through `ConfirmPayment(freshmanFirstPayment: true)`, which lifts only the
promissory-note rule. When the freshman was enrolled by that payment,
`amount` is what the Cashier took (at least PHP 1,000). When the payment was
held because every section was full, the money is a null-enrollment
`account_payments` row; when Registrar Staff later approve the freshman's own
enrollment, `SettleEnrollmentFromAdvanceCredit` re-points those rows to the
enrollment and writes the `payments` row with `amount = 0.00`, so the money
is counted once (in `account_payments`) and `enrollment_id`'s unique
constraint still makes a repeat a no-op. The enrollment of a freshman paid
this way goes from `pending_registrar_approval` to `pending_payment` by the
`freshman_auto_approve` transition (it is assessed there; no Registrar actor,
`registrar_decided_at` left null) and is audited as
`enrollment.freshman_auto_approved`; `ConfirmPayment` then moves it to
`enrolled` in the same transaction. The confirmation itself is audited as
`student_profile.freshman_payment_confirmed`.

## `enrollment_documents`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `enrollment_id` | `BIGINT UNSIGNED` | not null, FK → `enrollments.id`, `CASCADE` on delete | |
| `document_type` | `VARCHAR(255)` | not null | Deliberately single-valued (`com` only) — see `App\Domain\Enrollment\EnrollmentDocumentType`; whether COR is a distinct artifact is unconfirmed (PRD §17) |
| `document_number` | `VARCHAR(255)` | not null | |
| `storage_path` | `VARCHAR(255)` | nullable | |
| `content_hash` | `VARCHAR(64)` | nullable | |
| `generated_at` | `TIMESTAMP` | not null | |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |
|  |  | **unique** `(enrollment_id, document_type)` — `enrollment_documents_unique_type_per_enrollment` | Generating twice must not produce a duplicate document |
|  |  | **unique** `(document_type, document_number)` — `enrollment_documents_unique_number_per_type` | |

**API (Phase 7a Task 5):** `GET /api/v1/enrollment-documents` (Student own,
Registrar Head and Registrar Staff all — the latter widened in Phase 7b
Task 3). Rows are created only as a side effect of payment
confirmation, never directly. `storage_path` stays `null` in this slice — no
PDF pipeline; the Digital COM is served as structured data for the Student
module to render as a print-stylesheet page (FR-FIN-010). OpenAPI tag
`Payments`.

## `transferee_credits`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `student_id` | `BIGINT UNSIGNED` | not null, FK → `student_profiles.id`, `CASCADE` on delete | |
| `source_institution`, `source_subject_code`, `source_subject_title` | `VARCHAR(255)` | not null | |
| `source_grade` | `VARCHAR(255)` | nullable | Free string — no equivalence/passing rule encoded (PRD §17) |
| `credited_units` | `DECIMAL(4,1)` | not null | Cast to `float` on the model. Widened from `TINYINT UNSIGNED` (ADR 0026) so a 1.5-unit subject fits |
| `source_school_year`, `source_semester` | `VARCHAR(255)` | nullable | When the student took the subject; required on a Student's request |
| `subject_id` | `BIGINT UNSIGNED` | nullable, FK → `subjects.id`, `SET NULL` on delete | The subject of the student's curriculum the credit counts as. Set only by the Program Chair (never guessed by the API); nullable — a transferred subject may not map onto any local subject yet |
| `requested_by` | `BIGINT UNSIGNED` | nullable, FK → `users.id`, `SET NULL` on delete | Who asked: the Student, or the Program Chair who recorded it. Never exposed (the resource says only `requested_by_student`) |
| `endorsed_by` | `BIGINT UNSIGNED` | nullable, FK → `users.id`, `SET NULL` on delete | The Program Chair who endorsed it |
| `endorsed_at` | `TIMESTAMP` | nullable | |
| `submitted_at` | `TIMESTAMP` | nullable | When the Student sent their saved list to the Program Chair (`draft` → `pending`) |
| `returned_by` | `BIGINT UNSIGNED` | nullable, FK → `users.id`, `SET NULL` on delete | The Registrar Head who last returned the credit to the Program Chair; cleared when it is endorsed again |
| `returned_at` | `TIMESTAMP` | nullable | |
| `return_reason` | `TEXT` | nullable | Why it was returned |
| `status` | `VARCHAR(255)` | not null | `draft` (the Student's unsent list) → `pending` → `endorsed` → `approved` \| `rejected`; the Registrar Head may return an `endorsed` credit to `pending`. **Provisional** — see `App\Domain\Academic\TransfereeCreditStatus` |
| `processed_by` | `BIGINT UNSIGNED` | nullable, FK → `users.id`, `SET NULL` on delete | |
| `processed_at` | `TIMESTAMP` | nullable | |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

**API (ADR 0026):** `GET`/`POST`/`PATCH /api/v1/transferee-credits` and
`GET /api/v1/transferee-credits/{id}/suggestions`. Role-scoped read (Student own,
Program Chair their college's students, Registrar Staff and Registrar Head all).
`POST`: a Student saves one for themselves as a private `draft`, a Program Chair
records one (`pending`) for a student in their college; Registrar Staff and Registrar
Head cannot. `POST /transferee-credits/submit` sends a Student's drafts and
`DELETE /transferee-credits/{id}` removes a draft. `PATCH` serves a Student's edit of
their own draft, a Program Chair's content edit / mapping (`pending` only), `action:
endorse` or `decline` (`pending` only), and the Registrar Head's `action: approve`,
`reject` or `return` (`endorsed` only). Registrar Staff read credits that have been
sent but cannot decide them; nobody but the owning Student sees a draft. Three
student-level endpoints serve the side-by-side screens: `GET /student-profiles/{id}/transferee-credits/suggestions`
(Program Chair, own college: suggestions for every `pending` credit and the student's
curriculum subjects), `POST .../submit-to-registrar-head` (Program Chair: all of the student's
`pending` credits become `endorsed`, all or nothing) and `POST .../approve-all` (Registrar Head:
all of the student's `endorsed` credits become `approved`); the last two are idempotent.
`suggestions` is Program Chair only and computed on demand — nothing is stored. Every
write is audited (FR-FIN-003). An approved credit that is mapped to a subject counts as
credited (see `ResolveCreditedSubjectIds`: eligible-subject pool, standing, promotion,
prospectus) with no GRC grade, so cross-institution grade equivalence (PRD §17) stays
open. OpenAPI tag `Transferee Credits`.

## `withdrawal_requests`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `enrollment_id` | `BIGINT UNSIGNED` | not null, FK → `enrollments.id`, `CASCADE` on delete | |
| `reason` | `TEXT` | not null | PRD §4.2 rule 7 requires a reason for withdrawal — a stated rule, so enforced as `NOT NULL` |
| `status` | `VARCHAR(255)` | not null | **Provisional** — see `App\Domain\Enrollment\WithdrawalStatus` |
| `processed_by` | `BIGINT UNSIGNED` | nullable, FK → `users.id`, `SET NULL` on delete | |
| `processed_at` | `TIMESTAMP` | nullable | |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

Seat-release mechanics (whether an approved withdrawal decrements
`sections.enrolled_count`) are config-flagged —
`config('enrollment.withdrawal.releases_seats')`, default `true` — since
§17 leaves the underlying policy unconfirmed; dropping the affected
`enrollment_subjects` rows to `dropped` happens unconditionally either way.

**API (Phase 7b Task 1):** `POST /api/v1/enrollments/{enrollment}/withdraw`
(Student, own `enrolled` enrollment — creates this row, `pending`), `GET
/api/v1/withdrawal-requests` (Student own, Registrar Staff and Registrar
Head all), `PATCH /api/v1/withdrawal-requests/{withdrawalRequest}`
(Registrar-Staff-only `action: approve`/`reject`). `approve` drops every
still-active `enrollment_subjects` row and, per the config flag above,
releases the section seat exactly once even under a repeated approval
attempt (`withdrawal_requests` carries no unique constraint on
`enrollment_id`, so idempotency is enforced under a row lock in
`App\Actions\Enrollment\TransitionWithdrawalRequest`, not the schema).
OpenAPI tag `Withdrawals`.

## `payment_discount_settings`

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | |
| `percentage` | `DECIMAL(4,1)` | not null | The full-payment discount, 0 to 100 with one decimal (the request enforces the range). **Not cast to float on the model** (cast `decimal:1`, so it reads as `10.0`). `0` turns the discount off |
| `valid_from` | `DATE` | nullable | First day the discount is on offer, inclusive; `NULL` means no start limit |
| `valid_until` | `DATE` | nullable | Last day the discount is on offer, inclusive; `NULL` means no end limit. Never before `valid_from` (the request enforces it) |
| `updated_by` | `BIGINT UNSIGNED` | nullable, FK → `users.id`, `SET NULL` on delete | The Registrar Head who last saved it; `NULL` until someone does |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | |

The configuration of the full-payment discount (ADR 0046, PRD FR-FIN-011 and
FR-FIN-012). **One row.** It is not created by the migration or a seeder:
`PaymentDiscountSetting::current()` creates it on first read from
`config('fees.full_payment_discount_percentage')` (`10.0`), so a restored older
dump cannot leave payment confirmation without a setting. There is no unique
constraint; two requests racing on the very first read could create two rows,
and every reader then uses the lowest `id`. `valid_from` and `valid_until` are
dates in the school's calendar (`Asia/Manila`), not UTC. The status (`off`,
`scheduled`, `active`, `expired`) is derived from today's date, never stored.
Editing the row never changes an assessment, payment or COR that already exists.

**API:** `GET /api/v1/payment-discount-settings` (Registrar Head and
Accounting Staff) and `PUT /api/v1/payment-discount-settings` (Registrar Head
only), authorized by `PaymentDiscountSettingPolicy` with no `role:` middleware.
A save is audited as `payment_discount.updated`; a save that changes nothing
writes no audit row. OpenAPI tag `Payments`.

## Seeded data

`database/seeders/DemoEnrollmentSeeder.php` seeds eight students with real
locked grade history spanning year 1 semester 1 through year 4 semester 2
(four Regular, four Irregular — see `docs/testing/SEEDED_IDENTITIES.md`),
proving `ClassifyEnrollmentStanding`'s derivation rather than hard-coding a
category. None of the eight carries an `enrollment` row of its own — every
one is left free to submit a real, fresh enrollment through the UI/API
against the current `semester_ongoing` term, at which point the tables on
this page (and the unique-active-enrollment rule) come into play for real.
The same seeder also creates 10 real-named professor accounts
(`prof.<surname>@grc.test`), each owning every block section of one of the
10 distinct subjects those students' generated blocks offer. `local`/`testing`
environments only; fails closed outside those environments. Every synthetic
login uses the shared development password `password`, stored only as a
Laravel hash. See `tests/Feature/Database/DemoEnrollmentSeederTest.php` and
`EnrollmentRecordsMigrationTest.php`.

## Reversibility

Each migration's `down()` drops only its own table; the migrator runs them in
reverse creation order, which also satisfies FK dependency order.

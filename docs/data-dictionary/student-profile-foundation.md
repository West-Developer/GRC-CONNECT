# Student Records and Account Setup Data Dictionary

**Database:** MariaDB 10.4.32 (`grc_enrollment` / `grc_enrollment_test`), per ADR 0007.

**Owning logical store:** PRD `STUDENT RECORDS`. This slice covers Admission provisioning and corrections, Student self-service profile reads, initial password setup, and Admission-approved profile change requests. Enrollment and academic-history fields remain documented in `enrollment-records.md`.

## Security and lifecycle rules

- Admission creates the Student `users` row and `student_profiles` row in one database transaction only after attesting that requirements were submitted.
- A new account is `disabled` with a server-generated hidden password until the Student successfully uses the emailed one-time setup code. The API never accepts or returns an initial password, setup code, or reset-token value.
- Curriculum is resolved from `program_id` plus `entry_year`; clients cannot select or override `curriculum_id`.
- Invitation delivery runs after commit. A delivery failure leaves the single pending account intact and records a resendable failed state. Resend replaces the prior code.
- Laravel's password broker stores only the hashed code and applies the configured 60-minute expiry. Successful setup is single-use, activates the account, replaces the hidden password, and deletes the code.
- Existing accounts are backfilled with `account_setup_completed_at` so this migration never disables working users.
- Students may propose only name, email, and complete address changes. The official record is unchanged until Admission verifies identity in person and approves the complete request atomically.
- Admission direct corrections require a reason and in-person verification. Academic setup fields are editable only before the first enrollment; curriculum and academic standing are never directly editable.
- Audit rows contain action metadata and changed field names/counts, not name, email, address, reason text, decision-note text, passwords, or setup codes.

## `users` additions

| Column | Type | Constraints | Producer / consumer | Sensitivity and notes |
|---|---|---|---|---|
| `account_setup_completed_at` | `TIMESTAMP` | nullable | Set by account activation; read by login and Student Records | Account lifecycle metadata. `null` means initial setup is still pending. |
| `account_setup_invitation_sent_at` | `TIMESTAMP` | nullable | Set after successful mail delivery; read by Admission | Delivery metadata; contains no email content or code. |
| `account_setup_invitation_failed_at` | `TIMESTAMP` | nullable | Set after failed mail delivery; read by Admission | Delivery metadata used to expose the resend action. |

The existing `users.status` remains the login enforcement field: pending setup accounts are `disabled`; successful setup changes it to `active`.

These three columns are shared, role-agnostic infrastructure — not Student-specific despite living alongside the Student Records slice. Program Chair's Faculty invitations (`InviteFacultyAccount`/`ActivateFacultyAccount`) and Registrar Head's staff invitations spanning every other non-Student role (`InviteStaffAccount`/`ActivateStaffAccount`, `UserRole::registrarInvitableCases()`) read and write the exact same three columns on `users`, following the same disabled-until-activated lifecycle. Unlike Admission's Student flow, both of those invite with only an email (plus, for staff, a role); the invitee supplies their own name when redeeming the setup code.

## `student_profiles` additions

| Column | Type | Constraints | Producer / consumer | Sensitivity and notes |
|---|---|---|---|---|
| `address` | `TEXT` | nullable for migration compatibility | Admission provisioning/direct correction or approved Student request; Student Information and future COR snapshots | Personal contact data. Written by the server from `street_barangay`, `town_city` and `province` (`App\Domain\Identity\StudentAddress`, "street, town, province") whenever Admission creates an account or corrects one of the three; a client may not send it (ADR 0049 follow-up of 2026-10-15). Older rows keep the free text they had; existing rows may display `Not provided`. |
| `requirements_verified_at` | `TIMESTAMP` | nullable | Set during Admission provisioning only when every applicable requirement was ticked (ADR 0041) | Evidence that Admission received everything; `NULL` means some requirements are still missing, which the checklist (`student_admission_requirements`) shows. |
| `requirements_verified_by` | `BIGINT UNSIGNED` | nullable FK → `users.id`, `NULL` on staff deletion | Set during Admission provisioning | Admission actor responsible for the attestation. |
| `student_type` | `VARCHAR(255)` | nullable, enum values `freshman`/`transferee`/`returnee`/`existing_student` (`App\Domain\Identity\StudentType`) | Chosen by Admission at provisioning (stakeholder Doc 20, ADR 0041); editable pre-enrollment correction only | How the student came to the school. It drives the credit-mapping gate (Transferee and Returnee must have their credits mapped before enrolling; Freshman and Existing Student are not gated) and which Admission requirement lists apply. Does not itself grant `TransfereeCredit` rows; Registrar staff still record those separately. Nullable at the DB level so pre-existing/seeded rows are unaffected. |
| `freshman_payment_confirmed_at` | `DATETIME` | nullable | Set once, when the Cashier confirms a freshman's or a transferee's first payment (ADR 0043, amended 2026-10-05); read by the account-setup email gate, the queue-cycle drain rule, the public live-queue stage and the transferee's payment hold | `NULL` for every other student and for a pay-first student (a Freshman or a Transferee) who has not paid. It is the idempotency key for that confirmation. The name says "freshman" but it is reused for transferees (no migration; a rename is cosmetic and later). `dateTime`, not `timestamp`, so MariaDB adds no implicit `ON UPDATE`. |
| `enrollment_deadline_at` | `DATETIME` | nullable, indexed | Set by Admission's account creation (and restarted by a new setup invitation after it passed) to 7 days ahead, only for a Transferee or a Returnee (ADR 0048); cleared by the Registrar's approval of an enrollment; read by the portal (Enrollment-only navigation and the deactivation banner), the hourly `students:deactivate-overdue` command, the account-setup activation and the invitation | `NULL` for every other student, for every account that existed before the rule, and once the Registrar has approved. While it is set the student sees only Enrollment. The date is kept after the overdue command deactivates the account (the account goes back to `disabled` with setup not completed), so the next invitation knows the 7 days ran out. `dateTime`, not `timestamp`, for the same reason as `freshman_payment_confirmed_at`. |
| `full_payment_discount_percentage` | `DECIMAL(4,1)` | nullable | The full-payment discount percentage quoted to a pay-first student (freshman or transferee) when the Cashier took a full payment (ADR 0046); applied to the assessment by the Registrar's approval only if the held credit covers the discounted net, then cleared | `NULL` for everyone else and for an installment. |
| `gender` | `VARCHAR(1)` | nullable, `M` or `F` (`App\Domain\Identity\Gender`) | Typed by Admission when the account is created or corrected (required for a new account, ADR 0049 follow-up of 2026-10-15); filled by `students:populate-demographics` for accounts that predate it; read by the CHED Enrollment List and NSTP Forms 2-B / 2-A | Personal data. `M` / `F` on the CHED list, `MALE` / `FEMALE` on Form 2-B. A first-name guess when generated: see `demographics_demo_fields`. |
| `birth_date` | `DATE` | nullable | Same as `gender`; read by NSTP Form 2-B (Birthdate) | Personal data. A generated demo value unless listed otherwise in `demographics_demo_fields`. |
| `citizenship` | `VARCHAR(100)` | nullable | Same as `gender`; read by the CHED Enrollment List | Personal data. Generated as `Filipino`. |
| `contact_number` | `VARCHAR(30)` | nullable | Same as `gender`; read by NSTP Form 2-B (Contact Number) | Personal contact data; excluded from audit payloads. |
| `street_barangay`, `town_city`, `province` | `VARCHAR(255)` | nullable | Same as `gender`; read by NSTP Form 2-B (Street/Barangay, Town/City, Provincial Address) | Personal contact data. Required for a new account (they compose `address`). Generated only for an older student with no written `address`; when empty, the form uses the free-text `address` for Street/Barangay. |
| `demographics_demo_fields` | `JSON` | nullable, list of field names | Written by `students:populate-demographics`; cleared by `--wipe`; counted by the CHED Reports API as `report.demo_populated` (no longer shown on the screen) | The names of the fields above that were generated, not entered by a person, so they stay recognisable and `--wipe` can take exactly those back. `NULL` for a student with nothing generated, and for every account Admission created after 2026-10-15. A detail whose value Admission changes in a correction is removed from the list (one sent unchanged stays in it). Exposed by the API as `generated_fields` (a list, empty when `NULL`). |

A **freshman** is the exception to the account lifecycle above (ADR 0043): Admission still creates the disabled `users` row and the `student_profiles` row, but it does not send the setup email. It issues a queue number (`queue_tickets.student_profile_id`) and emails that with a live-queue link (ADR 0044). The setup email is sent only once `freshman_payment_confirmed_at` is set; until then the API reports `account_setup_status: awaiting_payment` and every route to the setup email refuses the freshman. The API-derived `freshman_status` (`awaiting_payment` / `awaiting_section` / `enrolling`, null for everyone else; not a stored column) says where a paid freshman is: `awaiting_section` means the payment was held because every section was full and no enrollment is in progress, `enrolling` means an enrollment is in progress or done (ADR 0043). The API-derived boolean `advance_payment_held` (also not stored) is true while that freshman's payment is held as credit — `account_payments` rows with no enrollment summing to at least the first-payment minimum — and has not yet been applied to an enrollment; it is the exact condition `SettleEnrollmentFromAdvanceCredit` completes a Registrar approval from, so the student's own screens use it to say there is no queue number to claim and nothing more to pay. It is about the money, not the term: false once the credit is applied and for anyone who never held any.

The established fields `entry_year`, `program_id`, `curriculum_id`, `year_level`, `enrollment_category`, `financial_status`, and `admission_status` remain part of the profile. `academic_setup_editable` is an API-derived boolean (`false` once any enrollment exists), not a stored column.

Future COR snapshots copy the current approved `student_profiles.address`. Existing COR snapshot JSON is immutable and is never rewritten after a profile change.

## `password_reset_tokens`

Laravel password-broker storage is also used for initial Student account setup.

| Column | Type | Constraints | Producer / consumer | Sensitivity and notes |
|---|---|---|---|---|
| `email` | `VARCHAR(255)` | primary key | Invitation sender and account setup action | Identifies the pending account; one current code per email. |
| `token` | `VARCHAR(255)` | not null | Laravel password broker | One-way hash only; never serialized or logged. |
| `created_at` | `TIMESTAMP` | nullable | Laravel password broker | Used to enforce the configured 60-minute expiry. |

## `student_profile_change_requests`

| Column | Type | Constraints | Producer / consumer | Sensitivity and notes |
|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | primary key, auto-increment | API resource identifier | |
| `student_id` | `BIGINT UNSIGNED` | FK → `student_profiles.id`, cascade delete | Owning Student and Admission review | Ownership boundary. Indexed with `status`. |
| `requested_first_name` | `VARCHAR(255)` | not null | Student request/revision; Admission comparison/decision | Proposed personal data; does not change `users.first_name`/`users.name` until approval. |
| `requested_middle_name` | `VARCHAR(100)` | nullable | Student request/revision; Admission comparison/decision | Proposed full middle name (ADR 0053); optional. Added `2026_10_16_000001`. |
| `requested_middle_initial` | `VARCHAR(10)` | nullable | Student request/revision; Admission comparison/decision | Initial derived from the proposed middle name; what the proposed display name uses. |
| `requested_last_name` | `VARCHAR(255)` | not null | Student request/revision; Admission comparison/decision | Proposed personal data. |
| `requested_suffix` | `VARCHAR(20)` | nullable | Student request/revision; Admission comparison/decision | Proposed personal data; optional. |
| `requested_email` | `VARCHAR(255)` | not null | Student request/revision; Admission comparison/decision | Proposed personal data; uniqueness is rechecked at approval. |
| `requested_address` | `TEXT` | not null | Student request/revision; Admission comparison/decision | Proposed personal data. |
| `reason` | `TEXT` | not null | Student request/revision; Admission review | User-provided sensitive context; deliberately excluded from audit payload values. |
| `base_profile_updated_at` | `TIMESTAMP` | not null | Captured on create/revision; checked on approval | Optimistic stale-request guard. |
| `status` | `VARCHAR(255)` | not null | Request workflow | `pending`, `approved`, `rejected`, or `cancelled`. One pending request per Student is enforced by transactional application logic. |
| `decided_by` | `BIGINT UNSIGNED` | nullable FK → `users.id`, `NULL` on staff deletion | Admission decision | Admission actor who approved or rejected. |
| `decision_notes` | `TEXT` | nullable | Admission decision; Student status view | Required for rejection; excluded from audit payload values. |
| `identity_verified_at` | `TIMESTAMP` | nullable | Admission decision | Evidence of in-person verification. |
| `decided_at` | `TIMESTAMP` | nullable | Approval, rejection, or cancellation | Workflow completion time. |
| `created_at`, `updated_at` | `TIMESTAMP` | nullable | Laravel timestamps | `created_at` is indexed with `status`. |

## Regular and possible-Irregular outlook — 2026-2027 · 1st Semester

**Not a stored value.** `student_profiles.enrollment_category` holds only `regular`, `irregular`, or `NULL` (not yet classified). It is term-scoped and written by `ReclassifyStudentEnrollmentCategory` from `ClassifyEnrollmentStanding`. "Possible Irregular" is a planning label used in this outlook and in the roster fixture; there is no column, enum case, or API field for it, and it never denies or blocks an enrollment (predictive output is advisory, PRD §4).

**Rule behind the label (ADR 0028).** A student is Irregular for a term when a required subject placed earlier than their current position is not credited and was last graded with a mark that `GradeMark::blocksRegularStanding()` (Failed `5.00`, `NC`, `INC`, `DRP`) in a **prior** term. A mark recorded in the current term does not change standing until the next term. So a `5.00` or `DRP` earned in 2025-2026 · 2nd Semester counts as a back subject when 2026-2027 · 1st Semester is classified, and the student moves from Regular to Irregular. A failed subject does not hold back year-level promotion; the student moves up with the subject left behind.

**Snapshot — local development database, read-only, 2026-10-03.** Pool: students currently classed Regular with an `enrolled` enrollment in 2025-2026 · 2nd Semester (the 67 `withdrawn` enrollments are excluded). "Possible Irregular" is the subset with a `5.00` or `DRP` mark in that term. Year level is the current one, before the next promotion. This is development data, not production, and it is a point-in-time count: grade locking and promotion will change it.

| College | Year 2 Regular / possible | Year 3 Regular / possible | Year 4 Regular / possible | Total Regular / possible |
|---|---|---|---|---|
| CBAE | 248 / 12 | 122 / 3 | 132 / 7 | 502 / 22 |
| CCS | 198 / 11 | 144 / 7 | 122 / 7 | 464 / 25 |
| COA | 75 / 4 | 61 / 3 | 53 / 3 | 189 / 10 |
| COE | 200 / 10 | 66 / 3 | 72 / 4 | 338 / 17 |
| **All** | | | | **1,493 / 74** |

Outside this pool: 96 students are already `irregular`, 3,336 have no category yet (`NULL`), and the 2026 entrants (Year 1) have no enrollment or grades, so none can be a back-subject case. The marks present in the term are `5.00` and `DRP` only; no `NC` or `INC` rows exist, so none are counted.

**Roster fixture.** `backend/tests/fixtures/students-profile-irregular-sample.md` carries a 70-row sample of the same outlook (30 BSBA-FM Year 2, 30 BSA Year 3, 10 BSIT Year 1). Its `Category` column reads `Regular` or `Possible Irregular` and is for human scanning only: `StudentRosterReader` ignores it and `StudentRosterSeeder` derives its own irregular cohort (every 10th eligible Year 2-4 student), which the three Possible Irregular rows in each of the two eligible blocks line up with. It is a sample, not the roster of 1,493.

## API ownership and serialization

- `GET /api/v1/student-profile` and Student change-request operations resolve ownership from the Sanctum bearer identity; Students cannot supply another Student ID.
- Admission-only `/api/v1/student-profiles` routes support paginated name, student-number, and email search, verified corrections, and invitation resend.
- `StudentProfileResource` serializes approved identity/contact fields (the composed `name` alongside the split `first_name`/`middle_initial`/`last_name`/`suffix` — clients edit the parts, never the composed string), program/curriculum labels, permitted status labels, requirements time, academic editability, and delivery/setup state. It never serializes credential or token fields. `middle_name` (the full middle name) is sent only to Admission staff and to the student on their own profile; every other role gets null and every display of the name uses `middle_initial` (ADR 0053).
- Change-request resources show official and proposed identity/contact groups, each with the same composed-plus-split name shape, so clients do not present pending values as already official.
- Approvals and rejections notify the owning Student. Authorization is enforced by policy in addition to route middleware where applicable.

## Retention and reversibility

Official profile data is retained with the Student record under the institution's general records policy. Change requests are retained as decision history unless an approved retention policy later requires archival or deletion. Setup codes are temporary authentication material: expired values are rejected and successful setup or resend invalidates the prior code.

Migration `2026_08_26_000001_add_student_record_and_account_setup_fields.php` reverses the three User columns, the three Student-profile fields, and `password_reset_tokens`. Migration `2026_08_26_000002_create_student_profile_change_requests_table.php` drops the request table. Migration `2026_08_27_000001_add_student_type_to_student_profiles.php` drops `student_type`. Rollback does not attempt to restore already delivered emails or external mail-provider state.

# ADR 0042 — Sequential Student Numbers, an Existing-Number Option for Admission, and Platform "Both"

**Status:** Accepted
**Date:** 2026-10-04
**Amends:** Stakeholder Doc 17 (the Student number field on Create Account) and ADR 0041 (the form's student types)
**Related:** `docs/superpowers/specs/2026-10-04-sequential-student-number-and-platform-both-design.md`

## Context

The Student number was made in the **browser** (`YYYY-MM-` plus a random 5 digits, with a Generate button that re-rolled it), so it was not sequential and the server only checked its format and uniqueness. The Registrar's per-term platform offered only Online or Face-to-Face, although some terms run both. The owner asked for sequential numbers (still Year-Month-Sequential), no Generate button, an Admission option for a student who already has a number, and a "Both" platform.

The committed presentation data holds 6,379 numbers: 3,219 `YYYY-06-NNNNN` (2023: 00001-01660, 2024: 00101-01750, 2025: 01001-01870, 2026: 01001-01930), 3,000 `HIST-YYYY-NNNNN` and about 160 `TEST-…`.

## Decisions

1. **The server assigns the number**, inside the provisioning transaction (`App\Actions\Identity\AssignStudentNumber`). The browser no longer makes numbers; the Generate button and `student-number.ts` are gone.
2. **Format `YYYY-MM-NNNNN`, one running counter per year.** The counter continues across months and restarts at `00001` each January; the month is the month of creation. Year and month come from `Asia/Manila` (the app timezone is UTC), so a number made at 01:30 on 1 September in Manila says `-09-` even though it is still 31 August in UTC.
3. **Counter table `student_number_sequences`** (`year` primary key, `last_value`). The year's row is locked while a number is taken, so two staff creating accounts at once get different numbers. The row is created on first use from the highest existing `YYYY-MM-NNNNN` suffix of that year, so the table needs no seeding and a restored older dump cannot make it hand out a taken number. `HIST-` and `TEST-` numbers never match the pattern. A native `SEQUENCE` was rejected because production runs MySQL.
4. **A suffix already used by any student in the same year is skipped, in any month** (for example a number typed by hand). The suffix never passes `99999`; past that the request fails with a 422 on `student_number`.
5. **A failed provisioning gives the number back**: the counter update is part of the same transaction.
6. **`has_existing_student_number`** (default `false`) on `POST /student-profiles`. `false`: `student_number` must not be sent (an older frontend that still makes its own number gets a 422 instead of being silently ignored). `true`: `student_number` is required, in the same `YYYY-MM-NNNNN` format and unique ("This student number is already in use."), and the counter is not touched.
7. **Only a Returnee or an Existing Student may use it** (`StudentType::canHaveExistingStudentNumber()`), following ADR 0041, which treats those two as the students who already have records at the school. A Freshman has no number yet; a Transferee's number belongs to the school they came from.
8. **Existing numbers are not renumbered.** They are printed on CORs and grades and used by the presentation data.
9. **Audit:** `STUDENT_PROFILE_PROVISIONED` gains `student_number_source` (`assigned` or `existing`). The number itself still stays out of the audit payload.
10. **Platform "Both":** `EnrollmentPlatform::Both` (`both`, label "Both (Face-to-Face and Online)") is a third value of the Registrar's per-term platform and is printed on the COR. The column is a plain string, so there is no migration for it.

## Consequences

- One migration to run, `2026_10_04_000001_create_student_number_sequences_table`, on local and on Hostinger, **before** the new Create Account form is used (otherwise creating a student fails). The backend should be deployed together with, or just before, the frontend.
- A browser still running the old frontend gets a 422 on `student_number` until it reloads (same consequence as ADR 0041).
- The existing-number option accepts only `YYYY-MM-NNNNN`; any older number in another pattern is not accepted until its pattern is supplied.
- The dev and Hostinger databases are not touched by this change; the committed `DATABASE/grc_enrollment.sql.gz` has no `student_number_sequences` table (`php artisan migrate` after importing it creates the table, and the first number of each year continues from the highest existing one).
- Not decided here: whether a Transferee who already holds a GRC number should be allowed to keep it. **Decided 2026-10-04 by the owner: yes** (see the amendment below).

## Amendment 2026-10-04 — a Transferee may enter their existing number; the form previews the next one

- **Who may enter an existing number:** every type except a Freshman (`StudentType::canHaveExistingStudentNumber()`, `studentTypeCanHaveExistingNumber` in the frontend). A Transferee's number from the school they came from is entered the same way as a Returnee's or an Existing Student's (`YYYY-MM-NNNNN`, unique). A Freshman is always given a new one. The "Only a Transferee, a Returnee or an Existing Student..." refusal is unchanged in every other way.
- **Preview before saving:** `GET /api/v1/student-profiles/next-student-number` (Admission Staff, the same permission as creating a profile) returns the number the next new account would get, from `AssignStudentNumber::preview()`. It reads the counter and writes nothing, so the number is **not reserved**: an account saved first takes it, and the form refreshes its preview after every save. The form shows it read-only while a new number is asked for; the number actually given is shown after the save as before.
- **Why the number is not reserved:** reserving it at form time would hold numbers for forms that are abandoned, and would leave gaps in the year's sequence. The trade-off is that the preview can be one number out of date while another Admission staff member saves an account.

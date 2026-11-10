# Runbook — merging duplicate faculty accounts (`faculty:merge-duplicates`)

## Symptom

A student's schedule shows a professor and section (for example *PATHFIT3 ·
FIL201 · Lourivie Nabuab*), but when that professor logs in, Grade Submission
and Teaching Schedule do not list the section or its students.

## Cause

There are two faculty accounts for the same person:

- a legacy **`@grc.test`** account, created by the seeded schedule, which owns
  the section; and
- the **credentialed** account the professor actually logs in with (a
  standardized `@grc.com` directory account, or a professor invited later with a
  real email).

Faculty visibility everywhere is `sections.professor_id = <logged-in user id>`,
so the professor only sees sections owned by their own account. The schedule
generator draws from *active* faculty, so as long as the legacy account stays
active the split comes back with every new term.

## The fix

```bash
cd backend

# 1. Dry run (default). Reports what would move and what could not be matched. Writes nothing.
php artisan faculty:merge-duplicates            # add --details to list every account

# 2. Apply. Writes a manifest and an audit row per merged account.
php artisan faculty:merge-duplicates --apply

# 3. Undo, if needed, from the manifest that --apply printed.
php artisan faculty:merge-duplicates --rollback="storage/app/faculty-merge/faculty-merge-<timestamp>.json"
```

The command is idempotent: after a merge the legacy account is disabled, so a
second run finds nothing to do.

### What it does

- Matches a legacy account to a credentialed account only when the **name
  (case- and whitespace-insensitive) and the college are equal** and there is
  **exactly one** such account. Ambiguous or missing matches are reported, never
  guessed.
- Moves the professor's identity columns to the twin: `sections.professor_id`,
  `faculty_assignment_recommendations.recommended_professor_id`, and
  `professor_id` in `faculty_availabilities`, `faculty_subject_preferences`,
  `faculty_curriculum_subject_preferences`, `faculty_specializations`,
  `faculty_teaching_histories`.
- Leaves audit provenance alone (`academic_grades.encoded_by`, notification
  ownership).
- If the twin already has a row with the same unique key, the twin's row wins and
  the legacy row stays where it was (listed as `kept` in the manifest).
- Disables the legacy account (`status = disabled`, `deactivation_reason =
  "Merged into faculty account #N (faculty:merge-duplicates)"`). It is never
  deleted. `EnsureUserIsActive` blocks it and the faculty pickers stop offering it.
- Writes `storage/app/faculty-merge/faculty-merge-<timestamp>.json` listing every
  row moved or kept. `--rollback` moves a row back only if it still points at the
  twin, so later reassignments are never overwritten.

### Legacy professors with no match

The dry run ends with a table of legacy accounts that were **not** matched. Those
professors have sections in the schedule but no credentialed account, so nobody
can log in as them:

1. Have the Program Chair invite them (**Invite Professors**) with the same name
   and college.
2. Run the command again. The new account becomes their twin and the sections
   move automatically.

A legacy account with no college on it cannot be matched at all; fix the college
first.

## Sharing the result

The merge changes data in your local database only. Teammates run the same
command against their own copy. Do not re-export `DATABASE/grc_enrollment.sql`
just for this unless the team asks for a new dump.

## Verify

```sql
-- Sections in the active term that still belong to a legacy account
select count(*) from sections s join users u on u.id = s.professor_id
where s.academic_term_id = <term id> and u.email like '%@grc.test';
```

Then log in as the professor and check **Grade Submission**; log in as one of
their students and confirm the same professor and section appear.

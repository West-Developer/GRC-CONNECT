# ADR 0054 — IT Control Edits, Archives and Deletes Student and Faculty Accounts; the Enrollment Override Module Is Removed

**Status:** Accepted
**Date:** 2026-10-16
**Amends:** the IT Control portal (docs/superpowers/plans/2026-08-09-it-control-portal.md): its account browsers were read-only and it had an "Enrollment Overrides" module.

## Context

The owner asked for two things in IT Control: the ability to **edit**, **delete** and **archive** an account, and to **remove the Enrollment Override module**, which is no longer needed. IT Control had two read-only account browsers (Students, Faculty) and the Enrollment Overrides module, which started "run all" automation steps (generate every section, approve every enrollment, and so on) as background jobs.

## Decisions

1. **Enrollment Overrides is removed.** The module, its three `/it-control/automation-runs` endpoints, the six step actions, the queued job, the run model, the two policy abilities, their tests and the portal entry are deleted. Its table `it_control_automation_runs` is left in place on purpose (dropping a table is not reversible and nothing reads it); it can be dropped later. The old `/portal/it-control-enrollment-override` address is now simply "module not found".
2. **IT Control manages Student and Faculty accounts only.** Four endpoints under `/api/v1/it-control/accounts/{user}` (IT Control role only): `PATCH` (edit), `POST …/archive`, `POST …/restore` and `DELETE`. Each goes through the `manage-it-control-account` gate, which refuses any other role's account (staff, Super Admin, IT) with 403, so this screen can never be used to alter an administrator.
3. **Edit** changes the first name, last name, suffix and login email. The composed name is rewritten with the middle initial the account already has. The **middle name is not editable here and never sent**: only Admission and the student see it (ADR 0053). An empty edit and an email already in use are refused (422). The audit row names the fields changed, never their values.
4. **Archive** is how an account that has records is retired. The account is disabled and its sessions and any unused sign-in, setup or reset code are revoked; a new nullable `users.archived_at` marks it. It leaves the default Student and Faculty lists and appears under a new **Archived** status filter. Nothing about the person's records changes (enrollments, grades, payments and sections stay as they are). **Restore** clears the mark; the account becomes active again only if it had finished setting up its login and was not retired on purpose (a merged duplicate keeps its deactivation reason), otherwise it stays disabled exactly as before. Archiving twice or restoring an account that is not archived is a 409.
5. **An archived account cannot come back through a side door.** Activating an account with a setup code, sending a setup invitation (Admission's resend, the public resend, the faculty invitation) and the public resend all refuse an archived account, so a code that was already emailed does nothing.
6. **Delete is only for an account that was never used.** The check follows the database: every table with a foreign key to the user (and, for a student, to the student's profile) must hold no row for it, except a short allow-list of the account's own plumbing (sign-in and setup codes, its notices, a faculty member's own availability, preferences and specialization requests; for a student the queue number, admission checklist, change requests and schedule preferences). Any other table, including one added later, blocks the delete by default, so a section never loses its professor, a payment its student, and an account that appears in the audit trail is never removed. The answer is 409 "This account has records, so it cannot be deleted. Archive it instead." A delete removes the student profile with the account; a race with a new row is refused by the database and answered the same way.
7. **UI.** Each row of the Student and Faculty browsers gets Edit, Archive (or Restore) and Delete. Archive, Restore and Delete ask for confirmation; Delete says what the server will refuse and shows its answer if it does. The status column reads "Archived" for an archived account.

## Consequences

### Amendment — 2026-11-05

The owner requested **Change password** in the Student/Faculty account actions.
IT Control can submit `POST /api/v1/it-control/accounts/{user}/password` with
`password` and `password_confirmation`. The existing management gate is checked
again under the user row lock. Only active, unarchived accounts with completed
setup are eligible; this does not activate an account or replace the pay-first
freshman setup flow. The shared strength policy applies, with a 72-byte UTF-8
limit to prevent bcrypt truncation. Password fields are masked in the dialog.

The transaction changes the hash, revokes the target's sessions and unused setup,
reset and login-OTP codes, clears OTP grace, and records
`user_account.password_changed` with session-revocation counts only. No password,
hash or contact data enters the audit payload or API response. Existing sign-in
OTP policy still applies. Password mutation variables are removed from the client
mutation cache after completion/unmount. No schema migration or email delivery is
required. The route surface test pins the new endpoint and IT-only middleware.

- One migration (`2026_10_16_000002`, adds `users.archived_at`), reversible; run it on every environment before the new screens work.
- Only the account is archived. A student with an enrollment who is archived still appears in rosters, reports and the Registrar's lists, because those read the student's record, not the login. If the owner wants archived students hidden elsewhere, that is a separate decision.
- The audit trail gains `user_account.updated`, `user_account.archived` and `user_account.restored`; `user_account.deleted` is reused.
- IT Control endpoints are not in `docs/api/openapi.yaml` (nor are the Super Admin ones); the route surface test (`ApiSurfaceTest`) pins the four new routes and their `role:it_admin` guard.

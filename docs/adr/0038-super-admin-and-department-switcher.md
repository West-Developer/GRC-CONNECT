# ADR 0038 — Super Admin role and the Department Switcher

**Status:** Accepted
**Date:** 2026-10-01
**Amends:** PRD §3 (new operator identity, not a tenth primary actor — same framing as
the `queue_kiosk` note), §8.4 (new endpoint groups), §9.1/§9.4 (authentication and
authorization), §10.4 (audit log columns).
**Related:** ADR 0008 (role middleware and Policies), ADR 0003 (Sanctum bearer
authentication), ADR 0023 (precedent for a scoped, non-`'*'` token ability).

## Context

The owner needs one account, bound to their own email, with full operational
control of the deployed system — account management, incident response, and the
ability to actually use every department's workspace rather than only read about
it. No such role or mechanism exists today: PROGRESS.md previously recorded "there
is no SuperAdmin role," and the authorization system (ADR 0008) has no bypass —
every Policy, Gate, the `role:` route middleware, and every `scopeVisibleTo` query
scope compares `$user->role` and `$user->college` directly, with no
`Gate::before`.

Three approaches for "use every department's features" were considered:

1. **Impersonation** — sign in as a specific existing account. Rejected: every
   record and notification would show that person's name as the actor, which is
   factually wrong for an operator action, and it does not help when no professor
   or student account is the right target.
2. **One combined sidebar with every module from every role.** Rejected: the
   frontend has roughly 108 individual `session.role === …` checks across 65
   files, several pages render only one role's variant of shared content (e.g. the
   credit-mappings module), and the backend has about 25 inline role/college
   checks outside the route middleware. Flattening all of that into one
   simultaneous view is a large, fragile change for no benefit over switching.
3. **A Department Switcher** — the super admin explicitly selects one office at a
   time and gets that office's real, unmodified workspace under their own
   identity. Chosen.

## Decisions

1. **New role, CLI-provisioned only.** `UserRole::SuperAdmin = 'super_admin'`.
   It is never created by the invitation system, never seeded (`RoleUserSeeder`
   explicitly skips it), and no API endpoint can create, change the role of,
   deactivate, or delete one. The only way to create or deactivate it is
   `php artisan super-admin:provision` / `--deactivate`, reading the email from
   `SUPER_ADMIN_EMAIL` (never committed). This keeps the most powerful account
   outside the attack surface of every account-management endpoint it will later
   control.
2. **Department Switcher via a per-token acting context, not impersonation.**
   `personal_access_tokens` gains nullable `acting_role`/`acting_college`
   columns. `User::getAttributeValue('role'|'college')` is overridden to return
   the acting value when one is set, while the database row and every write
   (`getAttributes()`, `toArray()`, saves) keep seeing the real `super_admin`
   value. This was deliberately implemented as a `getAttributeValue` override and
   **not** as an `Attribute` accessor: an accessor on an enum-cast column crashes
   `toArray()`/`toJson()` ("Cannot instantiate enum"), confirmed against the
   installed framework source before implementation. Because every existing
   authorization layer reads the same cached per-request `$user` instance, the
   role middleware, every Policy and Gate, every `scopeVisibleTo` scope, and the
   two `->role->value === 'program_chair'` literal checks all keep working with
   no changes.
3. **Switchable offices exclude Faculty and Student.** Those two roles' features
   are bound to one person's own records (sections actually taught, one's own
   enrollment and grades); there is no coherent way to "act as the office" for
   them the way there is for Admission, Program Head/Dean (with a college),
   Executive Director, Registrar Head/Staff, Accounting, and IT Control.
4. **A stale browser tab cannot silently act as a different office than it
   shows.** One bearer token can be open in multiple tabs; the frontend sends
   `X-Acting-Context` on every request and a mismatch against the token's stored
   context returns 409, forcing a resync before anything executes.
5. **Attribution stays honest; accountability stays complete.** Domain write
   columns (`approved_by`, `confirmed_by`, …) always store the super admin's own
   id — records never show a borrowed name. `audit_logs` gains nullable
   `acting_role`/`acting_college` so every action remains traceable as, for
   example, "Super Admin (as Dean · CCS)," and these actions remain visible in
   the existing Registrar Head Audit Logs screen rather than a separate, hidden
   log.
6. **Account management (Accounts & Access) is Console-mode only.** While acting
   as an office, every account-management endpoint returns 409. This guarantees
   an invite, role change, deactivation, or deletion is never attributed to a
   borrowed office, and keeps the console's own powers legible as the super
   admin's own, not "Dean deleted a user."
7. **Permanent delete is restricted to never-used accounts.** `audit_logs.actor_user_id`
   is `RESTRICT` on delete by existing design (ADR/data-dictionary), and roughly
   fifty other foreign keys reference `users` with mixed cascade/restrict/null
   behavior accumulated across many prior slices. Rather than re-auditing all of
   them by hand for this feature, the delete check queries
   `information_schema` at request time for *any* referencing row before
   allowing deletion, and otherwise returns 409 "Deactivate instead." This keeps
   the guarantee correct even as future migrations add new foreign keys to
   `users`.
8. **Admission Staff becomes invitable** (via the new console only — not via the
   existing Registrar Head "Invite Staff" endpoint, which keeps today's
   boundary) to close a real gap: no account can create Admission Staff today.

## Consequences

- No production route, Policy, or Gate needed to change to accommodate the new
  role — only the identity, the acting-context middleware, and the new
  account-management surface are additions.
- Every existing role-matrix test (loops over `UserRole::cases()`) automatically
  asserts the new case is forbidden everywhere it isn't explicitly allowed — the
  established, desired default from the `it_admin` precedent (ADR 0008's
  consequence, reaffirmed here).
- The frontend's strict zod schemas and the backend's exact-shape resources must
  change together wherever a user's role is serialized into a narrower-than-full
  enum (found and fixed once here: `ScheduleProposalResource`'s
  `returned_by_role`/`decision_history[].actor_role` against
  `scheduling-schema.ts`); the same discipline applies to any future narrow role
  field.
- Because Google Sign-In matches by email with no domain restriction and skips
  OTP, the super admin account's practical security is bounded by the security of
  the owner's Gmail account. The runbook (Slice 4) requires Google 2-Step
  Verification as a condition of relying on this design, not as an optional
  suggestion.
- Token lifetime policy for this account is not decided here; it remains the
  existing open institutional decision in PRD §17.

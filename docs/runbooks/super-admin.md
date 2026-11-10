# Super Admin Operations & Incident Response Runbook

**Audience:** System Administrators, DevOps Engineers, and Project Owners.  
**Related Documents:** [ADR 0038](../adr/0038-super-admin-and-department-switcher.md), [PRD v3.3](../../PRD.md).

---

## 1. Overview and Security Architecture

The **Super Admin** (`super_admin`) role provides top-level administrative oversight, cross-office operational continuity, and emergency intervention capabilities for the GRC Enrollment System.

Key architectural boundaries:
- **No Direct Seeders:** Super Admin accounts are **never** seeded by default database seeders (`RoleUserSeeder`, `DemoEnrollmentSeeder`). They must be explicitly provisioned via the command line or authorized via environment variable configuration.
- **Acting Context Mechanism:** A Super Admin never operates with ambiguous privileges. When acting as a departmental role (e.g., Dean, Registrar Head, Accounting Staff), their active Sanctum token carries an explicit acting context (`acting_role` and `acting_college`).
- **Complete Audit Accountability:** Every operational action taken while in an acting context records both the primary user ID and the acting role/college in `audit_logs` (`acting_role`, `acting_college`), ensuring absolute forensic transparency.
- **Console Mode Isolation:** Privileged user account management operations (invitations, role reassignment, account deactivation, account deletion) are strictly prohibited while in an acting context. The administrator must explicitly exit acting mode back to Console mode.

---

## 2. Production Provisioning (VPS / Dokploy)

### 2.1 Dokploy Environment Setup

In the Dokploy dashboard for the `backend` container service:
1. Navigate to **Environment Variables**.
2. Add or update the Super Admin environment variable:
   ```env
   SUPER_ADMIN_EMAIL=admin.institutional@grc.edu.ph
   ```
3. Deploy/re-save the environment configuration.

### 2.2 Database Migrations

Before provisioning, verify all schema migrations are applied:
```bash
php artisan migrate --force
```
Ensure that the following migrations have executed:
- `2026_10_01_000001_add_acting_context_to_personal_access_tokens.php`
- `2026_10_01_000002_add_acting_context_to_audit_logs.php`

### 2.3 Command-Line Provisioning

To create or promote an institutional account to Super Admin, execute the Artisan command inside the backend container:

```bash
# Interactive provisioning (prompts for initial password if new account)
php artisan super-admin:provision admin.institutional@grc.edu.ph --name="System Administrator"
```

If the user account already exists with another staff role, the command will prompt for confirmation before upgrading the role to `super_admin`.

---

## 3. Identity Provider & Multi-Factor Security (MFA)

Because a Super Admin can assume the permissions of any institutional office, account security is critical.

### 3.1 Google 2-Step Verification Prerequisite
If Google OAuth / SSO is enabled:
1. The Google Workspace or Gmail account assigned to `SUPER_ADMIN_EMAIL` **must have Google 2-Step Verification (2SV) enforced**.
2. Hardware security keys (FIDO2/WebAuthn) or time-based authenticator apps (TOTP) are strongly recommended over SMS verification.
3. Access to the administrative Google account should be restricted by IP or device management policies if supported by Google Workspace.

### 3.2 Password Policy for Direct Credentials
If authenticating via direct email/password:
- Passwords must be at least 16 characters long with high entropy.
- Do not reuse credentials across development, staging, or other institutional platforms.

---

## 4. Operational Usage & Department Switcher

### 4.1 Accessing the Portal
1. Navigate to the web application (`https://enrollment.grc.edu.ph/login`).
2. Log in using the provisioned Super Admin credentials or Google SSO.
3. Upon authentication, you will arrive at the **Super Admin Console**, displaying:
   - **Accounts & Access:** Centralized management for staff and student accounts.
   - **System Audit Logs:** Immutable institutional event log.

### 4.2 Assuming an Office (Department Switcher)
To perform operational tasks on behalf of a department (e.g. approving a section proposal as Dean, confirming payments as Accounting Staff):
1. In the top navigation bar, locate the **Department Switcher** dropdown.
2. Select the target office and college (e.g. *Dean — College of Computer Studies* or *Registrar Head*).
3. Confirm the switch in the modal.
4. The application state and TanStack Query caches will immediately reset, and the navigation menu will reflect the target office's workspace modules.
5. An amber indicator banner will appear at the top of the interface reminding you that you are acting on behalf of that office.

### 4.3 Exiting Acting Mode
To return to the Super Admin Console:
1. Click **Exit Acting Mode** in the amber top banner or in the Department Switcher dropdown.
2. The session will revert to Console mode (`super_admin`), restoring the Accounts & Access and Audit Logs modules.

---

## 5. Incident Response & Emergency Procedures

### 5.1 Immediate Account Deactivation (Kill-Switch)
If a Super Admin credential or session is suspected of being compromised:

```bash
# Immediately deactivate the account and revoke all active Sanctum tokens
php artisan super-admin:provision admin.institutional@grc.edu.ph --deactivate
```

This command:
1. Sets `users.status = "disabled"`.
2. Permanently deletes all active personal access tokens for the account.
3. Emits an audit event `super_admin.deactivated`.

### 5.2 Reactivating the Account
Once the incident is contained and credentials are rotated:

```bash
php artisan super-admin:provision admin.institutional@grc.edu.ph
```
The account status will be restored to `active`.

### 5.3 Mass Session Revocation
To revoke all sessions for a specific managed user without disabling their account:
1. Navigate to **Super Admin Console > Accounts & Access**.
2. Locate the user in the table.
3. Open the row actions menu (`...`) and select **Revoke All Sessions**.
4. Alternatively, via Tinker:
   ```bash
   php artisan tinker --execute="App\Models\User::where('email', 'target@grc.edu.ph')->first()?->tokens()->delete();"
   ```

### 5.4 Troubleshooting Acting Context Conflicts (HTTP 409)
If a Super Admin has multiple browser tabs open and changes their acting office in Tab A, Tab B may receive a `409 Conflict` when making requests with outdated role headers.
- **Resolution:** The frontend automatically detects 409 acting context mismatches, refreshes user session via `GET /api/v1/auth/me`, clears query caches, and displays a toast notification ("Your workspace changed in another tab — refreshed."). If unexpected state persists, click **Exit Acting Mode** or log out and log back in.

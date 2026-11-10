# ADR 0007 — MariaDB 10.4 as the Local Development Database

**Status:** Accepted with mandatory revisit
**Date:** 2026-07-27
**Review by:** Before any production-like deployment or hosting decision

## Context

PRD.md specifies MySQL 8 LTS as the database. The workstation's only
installed server is XAMPP MariaDB 10.4.32. An earlier session attempted to
provision an isolated, checksum-verified MySQL 8.4.10 LTS instance beside
XAMPP on loopback port 3307, operated through repository-owned PowerShell
lifecycle scripts (`scripts/mysql84/`, since deleted).

That effort produced 2,628 lines of PowerShell across a bootstrap script, a
shared safety library, and lifecycle/status/stop scripts, and entered an
independent security/spec review that reached four rounds of fixes. Each round
addressed a real defect in the scripts themselves (canonical path handling,
option-file content validation, MySQL comment-character truncation in paths,
byte-encoding/BOM/newline differentials), but the review was auditing code
that had never once been executed: `C:\xampp\mysql84` was never created, and
port 3307 never had a listener. No migration, seeder, or database behavior had
been verified against a real MySQL 8 server at any point.

The user decided to stop that effort and use the existing XAMPP MariaDB
10.4.32 instance directly instead.

## Decision

Use the existing XAMPP MariaDB 10.4.32 server on `127.0.0.1:3306` as the local
development and test database for this phase, rather than installing an
isolated MySQL 8.4 instance.

- Add three least-privileged principals (`grc_app`, `grc_migrator`,
  `grc_test`) scoped to `grc_enrollment` / `grc_enrollment_test`. XAMPP's
  configuration files, other databases, and the `root` account are left
  exactly as found.
- Laravel's built-in `mariadb` connection driver (already present in
  `config/database.php`) is used instead of the `mysql` driver.
- `utf8mb4_0900_ai_ci`, introduced in MySQL 8.0, does not exist in MariaDB.
  The identity schema's collation is `utf8mb4_unicode_ci` instead — the
  closest Unicode-aware collation MariaDB 10.4 supports.
- Strict SQL mode and UTC storage, which the design assumed the server would
  enforce globally, are instead enforced per-connection through Laravel's
  `'strict' => true` connection option, since the shared XAMPP server's global
  `sql_mode` and `time_zone` are not changed.

## Consequences

- **PRD deviation:** the running server is MariaDB 10.4, not MySQL 8 LTS.
  MariaDB 10.4 itself reached end of life in June 2024 and receives no further
  upstream security patches. This must be resolved before any production
  deployment decision; it is accepted here only as a local development
  convenience.
- Any MySQL-8-specific SQL feature (window function differences, JSON
  function surface, `utf8mb4_0900_ai_ci` collation ordering, `caching_sha2_password`
  auth) must not be assumed to work identically once a real MySQL 8 server is
  used. Migrations in this phase avoid MySQL-8-only syntax.
- A future migration to real MySQL 8 (or MySQL 8.4 LTS as originally planned)
  requires: reinstating a MySQL 8 instance, re-pointing the Laravel
  connection, re-running every migration against it, and re-verifying the
  collation choice does not change sort order for any already-seeded data.
  The three-table schema in this phase has no data-dependent sort behavior
  yet, so this is expected to be low-risk today and to grow riskier the more
  data accumulates on MariaDB.
- The abandoned MySQL 8.4 design and plan documents remain in
  `docs/superpowers/` marked superseded, for historical record; they are not
  deleted so the review history and lessons (validate real execution early,
  not just static review of never-run code) are not lost.
- This ADR does not authorize treating MariaDB as the final production
  database. Revisit at the same time as ADR 0002's Laravel/PHP upgrade
  decision.

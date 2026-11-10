# MariaDB Local Development Runbook

Covers the existing XAMPP MariaDB 10.4.32 instance used for local development
per ADR 0007 — **not** an isolated install. If `C:\xampp\mysql\bin\mysqld.exe`
is not already running, start it from the XAMPP Control Panel first.

## Known instability — history and resolution

> **Resolved 2026-08-05 by a datadir rebuild.** The rules below are kept as
> history because the *cause* was misdiagnosed for weeks and the wrong
> workaround was written into this runbook. See "Instance rebuild" for what
> actually fixed it.

This instance crashed repeatedly, confirmed via Windows Application Event Log
(`Get-WinEvent -FilterHashtable @{LogName='Application';
ProviderName='Application Error'}`, filtered for `mysqld`):

- once independently, unrelated to any development session;
- reproducibly on schema-wildcard `GRANT ... ON dbname.* TO ...`, twice in a
  row, even immediately after a clean restart and a clean `CHECK TABLE
  mysql.db`.

**The old workaround was: never use schema-wildcard grants, only table-level
grants.** The crash was attributed to a corrupt
`C:\xampp\mysql\bin\VCRUNTIME140.dll`, since the faults landed at a fixed
offset inside that DLL.

**That diagnosis was wrong.** The real cause was **corruption in the Aria
system tables that hold privileges** (`mysql.db`, `mysql.columns_priv`, …).
A schema-wildcard `GRANT` writes `mysql.db`; a table-level `GRANT` writes
`mysql.tables_priv` — which happened to be healthy. That is the entire
reason table-level grants "worked" and wildcard grants "crashed". The DLL
was never at fault, and was never replaced.

After the 2026-08-05 rebuild onto a fresh datadir, a schema-wildcard
`GRANT ... ON db.* TO ...` was run as an explicit canary and **succeeded with
the server surviving**. Schema-wildcard grants are safe again.

Two rules from that era still stand on their own merits:

- **Do not append `FLUSH PRIVILEGES` to a `GRANT`.** `GRANT` applies
  immediately in MariaDB; the flush adds a failure surface for no benefit
  (it once returned `ERROR 1030 ... Aria` after the grants had already
  applied). A flush is only required when hand-editing grant tables.
- Include `CREATE` in a grant targeting a table that does not exist yet —
  MySQL/MariaDB permits this for `CREATE` specifically; any other privilege
  on a nonexistent table fails with `ERROR 1146`.

If MariaDB stops responding mid-command ("Lost connection to MySQL server
during query"), check `netstat -ano | findstr :3306` and the Windows Event Log
before assuming it was just a slow query. **A live process listening on 3306
is not proof the server is usable** — after the 2026-08-05 crash loop it
stayed listening while refusing every connection at the handshake. Always
confirm with a real test query.

## Server will not start — InnoDB redo-log corruption

**Distinct from the Aria failure below, and more dangerous:** this one *does*
threaten `grc_enrollment` data, because that database is InnoDB. Signature in
`mysql_error.log`, on every startup attempt:

```
[ERROR] InnoDB: Missing MLOG_CHECKPOINT at <lsn> between the checkpoint <lsn> and the end <lsn>
[ERROR] InnoDB: Plugin initialization aborted with error Generic error
[ERROR] Plugin 'InnoDB' registration as a STORAGE ENGINE failed.
[ERROR] Unknown/unsupported storage engine: InnoDB
[ERROR] Aborting
```

Seen 2026-08-05 after `mysqld` crashed three times in one afternoon. What to
know before touching anything:

- **`innodb_force_recovery=1` is not enough** for this signature; it still
  aborts. Level `6` (skips redo-log apply entirely) does start the server.
- **Level 6 is read-only.** Any write fails with `ERROR 1036: Table '<x>' is
  read only`, so you cannot repair, `DROP`, or rebuild in this mode — it is
  strictly a salvage window.
- Pass it on the command line rather than editing `my.ini`, so it cannot be
  left enabled by accident:
  ```powershell
  C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini `
    --innodb-force-recovery=6 --skip-grant-tables --standalone
  ```
- **Deleting `ib_logfile0`/`ib_logfile1` to force a fresh log makes things
  worse, not better.** The regenerated log starts at a lower LSN than the
  data pages, and every page then reports `log sequence number ... is in the
  future!`. At that point the instance is unrecoverable in place — plan on a
  rebuild rather than trying to salvage the datadir.

### `SHOW COLUMNS` crashing the server (and why `mysqldump` fails)

If plain `SELECT` works but `SHOW COLUMNS` / `SHOW FIELDS` / `DESCRIBE` kills
the server outright (full core dump to `data\mysqld.dmp`), the cause is
corrupt **`mysql.columns_priv`** — those statements consult column-level
privileges, so a corrupt Aria privilege table takes the server down.
`mysqldump` issues `SHOW FIELDS` per table, so it dies on the first table and
looks like the data is unreadable. It usually isn't.

Two ways out, cheapest first:

1. **`--skip-grant-tables`** — no privilege lookup happens at all, so the
   crash path is bypassed entirely. This is what made a full `mysqldump` of
   every database possible during the 2026-08-05 recovery.
2. Repair the Aria system tables (procedure in the next section), which fixes
   the underlying corruption.

### Instance rebuild (last resort, but the reliable one)

Once pages report "in the future", rebuild rather than repair:

1. **Salvage first — do not skip this.** Start with
   `--innodb-force-recovery=6 --skip-grant-tables`, then `mysqldump` every
   database including the unrelated ones sharing this instance. Verify each
   dump ends with `-- Dump completed`; a truncated dump means a crash
   mid-dump. Record row counts now, to compare after restore.
2. Stop cleanly: `mysqladmin -u root shutdown`.
3. **Rename, never delete:** `data` → `data_broken_<date>`.
4. Recreate `data` from XAMPP's shipped pristine template at
   `C:\xampp\mysql\backup\` (simpler and more reliable on Windows than
   `mysql_install_db.exe`).
5. Start normally and confirm the log has **no** `ERROR` lines at all — not
   just that the server came up.
6. Recreate principals and grants (see below), restore every dump, and verify
   row counts against step 1.

`grc_enrollment` is fully reproducible from `migrations + seeders`, so a dump
restore and a `migrate --seed` are both valid; the dump is exact, the seed is
clean. Restoring the dump also preserves the `migrations` table, so
`migrate:status` stays consistent.

## Server will not start — Aria checkpoint/recovery failure

A **different** failure mode from the GRANT crash above: `mysqld` aborts on
every startup attempt, with `mysql_error.log` showing this exact cascade
(read bottom-up — it is one fault, not several):

```
Cannot find checkpoint record at LSN (1,0x......)
[ERROR] mysqld.exe: Aria recovery failed. Please run aria_chk -r on all Aria
        tables and delete all aria_log.######## files
[ERROR] Plugin 'Aria' registration as a STORAGE ENGINE failed.
[ERROR] Could not open mysql.plugin table. Some plugins may be not loaded
[ERROR] Failed to initialize plugins.
[ERROR] Aborting
```

The Aria transaction log's control file points at a checkpoint record that is
no longer in the log (e.g. after an unclean shutdown). Since every table in
the `mysql` system schema is Aria, the engine failing to register means
`mysql.plugin` can't open and the whole server aborts. **This does not
threaten `grc_enrollment` data** — it is InnoDB, and InnoDB recovers
independently of the Aria log.

Confirm `mysqld` is fully stopped (`Get-Process mysqld`, `netstat -ano |
findstr :3306`) before touching anything below.

1. **Back up outside the data directory** (MariaDB treats every subdirectory
   under `data\` as a schema, so a backup placed inside it becomes a bogus
   database): copy `C:\xampp\mysql\data\mysql\` and both `aria_log*` files to
   e.g. `C:\xampp\mysql\data_backup_<date>\`.
2. **Repair every `.MAI` file in `C:\xampp\mysql\data\mysql\`**, always with
   `--datadir=C:\xampp\mysql\data --require-control-file` (see gotcha below):
   ```powershell
   cd C:\xampp\mysql\data\mysql
   Get-ChildItem *.MAI | ForEach-Object {
     & C:\xampp\mysql\bin\aria_chk.exe --datadir=C:\xampp\mysql\data --require-control-file -r $_.Name
   }
   ```
3. **Delete `C:\xampp\mysql\data\aria_log.00000001` and
   `C:\xampp\mysql\data\aria_log_control`.** Both regenerate on next startup.
   Leaving `aria_log_control` behind makes the server hunt for the same
   missing checkpoint again.
4. **Start MySQL** (XAMPP Control Panel, or `C:\xampp\mysql_start.bat` — note
   that script's window sometimes doesn't launch mysqld reliably; if the error
   log shows no new timestamp after a start attempt, invoke
   `mysqld.exe --defaults-file=mysql\bin\my.ini --standalone` directly instead).

### Gotcha: `--datadir` is required, or repair silently fails to re-stamp tables

Running `aria_chk -r` from *inside* `data\mysql\` with a bare relative
filename (`aria_chk -r plugin.MAI`) makes it look for `.\aria_log_control` —
i.e. `data\mysql\aria_log_control` — which does not exist (the real one is one
level up, in `data\`). It prints `Got error 'Can't find file' when trying to
use aria control file '.\aria_log_control'` and still exits 0, having repaired
the table *without* consulting the log. The result: `mysqld` starts and the
table looks intact (`SHOW GRANTS`, `SELECT` all work), but
`CHECK TABLE mysql.<table>` reports:

```
Table is from another system and must be zerofilled or repaired to be usable on this system
Corrupt
```

This hit exactly the privilege tables (`plugin`, `db`, `global_priv`,
`tables_priv`, `columns_priv`, `procs_priv`) after a checkpoint-recovery
incident — the ones stamped with an LSN from before the log was reset. Fix:

```powershell
cd C:\xampp\mysql\data\mysql
& C:\xampp\mysql\bin\aria_chk.exe --datadir=C:\xampp\mysql\data --require-control-file -z <table>.MAI
```
`-z` (`--zerofill`) is what actually clears the stale cross-system stamp;
plain `-r` repair alone does not, even once `--datadir` is fixed.
`--require-control-file` makes `aria_chk` abort loudly instead of silently
degrading if it still can't find the log — use it on every invocation during
this recovery. Restart `mysqld` and re-run `CHECK TABLE` after zerofilling; it
should report `OK`. Re-run `SHOW GRANTS FOR '<user>'@'<host>';` for `grc_app`,
`grc_migrator`, `grc_test`, and `pma` afterward to confirm privileges survived.

### Gotcha: `columns_priv.MAI` fails plain `-r` with "sort_buffer_size is too small"

Seen 2026-08-25 during a routine Aria checkpoint recovery (same signature as
above). Every other system table repaired cleanly with plain `-r`, but
`columns_priv.MAI` failed:

```
aria_chk.exe: error: aria_sort_buffer_size is too small. Current aria_sort_buffer_size: 16384  rows: 234  sort_length: 1006
aria_chk.exe: error: Create index by sort failed
Aria table 'columns_priv.MAI' is not fixed because of errors
```

Raising the buffer explicitly (`--sort_buffer_size=64M`, even `--sort_buffer_size=268435456`)
had **no effect** — `aria_chk` kept reporting the same `16384` regardless. `-o`
(`--safe-recover`) alone didn't help either. What worked, per `aria_chk --help`'s
own hint under `-f, --force` ("Add another `--force` to avoid 'sort_buffer_size
is too small' errors"), was **passing `-f` twice**:

```powershell
& C:\xampp\mysql\bin\aria_chk.exe --datadir=C:\xampp\mysql\data --require-control-file -r -f -f columns_priv.MAI
```

This switched the run from a sort-based rebuild to a plain check-and-fix path
and reported `was ok. Status updated`. Server survived a `SHOW COLUMNS` canary
afterward, confirming the table was actually healthy and not just marked so.

### Gotcha: `mysql.db`'s header itself is unreadable — `aria_chk` refuses, not just fails

Seen 2026-08-26 after `mysqld` crashed mid-migration (17+ minutes into a large
data backfill, unrelated to this signature otherwise). Startup aborted with:

```
[ERROR] Fatal error: Can't open and lock privilege tables: Incorrect file format 'db'
```

**More severe than the `columns_priv` gotcha above.** Both `aria_chk -r` and
`aria_chk -z` (even with `--datadir`/`--require-control-file` correct) refuse
outright:

```
aria_chk.exe: error: '../data/mysql/db.MAI' is not a Aria table
```

This means the `.MAI` header itself is corrupted beyond recognition, not just
mis-stamped or index-damaged — `-f -f` does not help either, because the tool
never gets far enough to attempt a repair. Every *other* Aria system table
(including `columns_priv`, `help_topic`, `proxies_priv`, which separately hit
the sort_buffer_size gotcha in the same incident) recovered fine with the
techniques above; `db` specifically did not.

Fix: swap in a fresh, empty copy of the table from XAMPP's own pristine
template rather than trying to repair the broken one in place — the same
"restore from template" idea the full rebuild section above uses, applied
surgically to one table instead of the whole datadir:

1. Confirm `mysqld` is fully stopped.
2. **Move the corrupted files aside, don't delete them**: `db.frm`, `db.MAD`,
   `db.MAI` (and any `db.MAD-<timestamp>.BAK` sitting next to them from a
   *previous* incident — their presence is itself evidence this exact table
   has failed this way before) out of `C:\xampp\mysql\data\mysql\`.
3. Copy `db.frm`, `db.MAD`, `db.MAI` from `C:\xampp\mysql\backup\mysql\` (the
   pristine, empty template XAMPP ships) into `C:\xampp\mysql\data\mysql\`.
4. Start `mysqld` normally. The log should show
   `[Note] Zerofilling moved table: '.\mysql\db'` and then either come up
   clean or hit the next broken table (repair those with the techniques
   above) — `db` itself will not error again.
5. **The freshly-installed `db` table is empty — no schema-level grants
   survive.** Re-apply the exact `GRANT` statements in "One-time setup"
   below for every project-specific user. `global_priv` (a *different* Aria
   table, usually undamaged) holds each user's existence and password hash
   independently of `db`, so `SELECT Host, User FROM mysql.global_priv;`
   while running with `--skip-grant-tables` tells you exactly which
   users need their grants restored — don't guess or re-grant unrelated
   users sharing the instance. In this incident only three project-specific
   users existed at all (`grc_app`, `grc_migrator`, `grc_test`); every other
   local project sharing the instance connects as `root`, which needs no
   `db` entry, so nothing else needed restoring.
6. Verify with the same checks as any recovery: `SHOW GRANTS FOR
   '<user>'@'<host>';` for every restored user, a DDL-denial canary for
   `grc_app` (see "Running migrations" below), and confirm `grc_migrator`
   still has full DDL rights.

Move the emergency backup of `mysql/` you took before starting *outside* the
datadir once you're done (e.g. `C:\xampp\db_recovery_<date>\`) — leaving it
inside `data\` makes MariaDB treat it as a bogus extra database, same as the
warning in "Instance rebuild" above.

## One-time setup: databases and principals

Run as `root` (no password on this instance; connects over `127.0.0.1:3306`).
Never pass a password as a command-line argument — pipe SQL via stdin.

```powershell
$appPw = <32-byte base64url secret, generated locally>
$migratorPw = <32-byte base64url secret, generated locally>
$testPw = <32-byte base64url secret, generated locally>

@"
CREATE DATABASE grc_enrollment      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE grc_enrollment_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'grc_app'@'127.0.0.1'      IDENTIFIED BY '$appPw';
CREATE USER 'grc_migrator'@'127.0.0.1' IDENTIFIED BY '$migratorPw';
CREATE USER 'grc_test'@'127.0.0.1'     IDENTIFIED BY '$testPw';
"@ | & 'C:\xampp\mysql\bin\mysql.exe' -u root -h 127.0.0.1 -P 3306
```

Then grant, **schema-level** (safe again as of the 2026-08-05 rebuild — see
"Known instability"). This is the current, preferred form:

```sql
-- grc_app stays DML-only: no CREATE/ALTER/DROP, per ADR 0007.
GRANT SELECT, INSERT, UPDATE, DELETE ON grc_enrollment.*      TO 'grc_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON grc_enrollment_test.* TO 'grc_app'@'127.0.0.1';

-- DDL principals.
GRANT ALL PRIVILEGES ON grc_enrollment.*      TO 'grc_migrator'@'127.0.0.1';
GRANT ALL PRIVILEGES ON grc_enrollment_test.* TO 'grc_test'@'127.0.0.1';
```

No trailing `FLUSH PRIVILEGES` — see "Known instability" for why.

Verify least privilege actually holds:

```powershell
php artisan tinker --execute="try { DB::statement('CREATE TABLE ddl_canary (id INT)'); echo 'FAIL: DDL allowed'; } catch (\Throwable \$e) { echo 'OK: DDL denied'; }"
```

## Running migrations

Migrations run as `grc_migrator` via a dedicated Laravel connection, keeping
the app's default connection (`grc_app`) DML-only:

```powershell
cd backend
php artisan migrate --database=mariadb_migrator --force
```

**No follow-up grant is needed for new tables.** The schema-level grant in the
previous section (`ON grc_enrollment.*`) covers every table the migration
creates, including future ones.

Verify: `php artisan tinker --execute="echo DB::table('users')->count();"`
should succeed; a `DB::statement('CREATE TABLE ...')` through the same
connection should fail (least privilege holds).

### Historical: the "every new table needs its own grant" trap

Under the old table-level-grant workaround, each migration that added a table
needed its own follow-up `GRANT ... ON grc_enrollment.<table>`, which was easy
to forget. The failure mode was a plain HTTP 500 from the API
(`SQLSTATE[42000]... SELECT command denied to user 'grc_app'`) that looked like
an application bug until you checked `SHOW GRANTS`. It bit at least three
times — `enrollment_change_requests`, `assessments`/`assessment_items`, and
`academic_term_year_level_windows`.

**The schema-level grant eliminates this class of bug entirely.** If you see
that 500 today, it means the schema-level grant is missing, not that a
per-table grant was forgotten.

## Rolling back

```powershell
php artisan migrate:rollback --database=mariadb_migrator
```

Drops all four tables in reverse migration order. Re-run `migrate` to restore
them.

## Seeding the nine role identities

Every synthetic identity uses the shared password `password`. The seeder
refuses to run outside `local`/`testing`, and the password is stored only as a
Laravel hash.

```powershell
php artisan db:seed --class=RoleUserSeeder
```

See `docs/testing/SEEDED_IDENTITIES.md` for the nine emails/roles this
creates. Re-running updates the same nine rows; it does not duplicate or
touch unrelated users.

## Resetting the test database

The test suite uses `grc_enrollment_test` via `grc_test` (already has full
DDL rights on the five planned tables). `RefreshDatabase` in PHPUnit handles
migration/rollback automatically per test run — no manual reset is normally
needed. To force a clean rebuild:

```powershell
php artisan migrate:fresh --env=testing --force
```

## Environment files

- `backend/.env` — `grc_app`/`grc_migrator` credentials, gitignored.
- `backend/.env.testing` — `grc_test` credentials, gitignored.
- `backend/.env.example` / `.env.testing.example` — committed templates with
  empty passwords.

Never write a real password into any committed file, `PROGRESS.md`, or a
chat/log transcript.

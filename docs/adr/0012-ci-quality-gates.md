# ADR 0012 — CI Quality Gates via GitHub Actions

**Status:** Accepted
**Date:** 2026-07-28

## Context

Every quality gate this project relies on — `composer test`/`analyse`/
`format:check`/`audit` for the backend, `format:check`/`lint`/`lint:fast`/
`typecheck`/`test`/`build`/`audit` for the frontend, `ruff`/`mypy`/`pytest`/
`pip check`/`pip-audit` for the ml-service, and the Redocly OpenAPI lint —
has been run manually before every merge across every prior slice. Nothing
enforced that automatically; a future push could skip any of them without
anyone noticing until much later. `.github/workflows/` did not exist before
this change.

## Decisions

**Four independent jobs, not one.** `AGENTS.md` requires keeping `frontend/`,
`backend/`, and `ml-service/` independently runnable; the CI structure
mirrors that directly (`backend`, `frontend`, `ml-service` jobs), plus a
fourth `docs` job for the OpenAPI document, which belongs to none of the
three services specifically. All four run in parallel on every push to
`main` and every pull request — no path filtering. The repository is small
enough that always running everything is simpler and safer than maintaining
filter rules that could silently skip a job when they need updating.

**MariaDB, not MySQL, backs the backend job.** ADR 0007 already chose
MariaDB 10.4.32 for local development over the PRD's original MySQL 8 LTS
target, and specifically for reasons that matter here too: MariaDB's
`utf8mb4_unicode_ci` collation differs from MySQL 8's `utf8mb4_0900_ai_ci`,
and this project's `enrollments.active_academic_term_id` generated column
was written and verified against MariaDB's specific `STORED` generated-column
behavior. Every one of the 335 backend tests currently passing was verified
against MariaDB. Running CI against MySQL instead would silently test a
different database than the one actually shipped, which defeats the point of
having CI at all.

**A single root-equivalent database user in CI, not the local machine's
`grc_app`/`grc_migrator`/`grc_test` split.** That three-principal,
least-privilege design (documented across several `PROGRESS.md` entries and
the mariadb-instability incident record) exists specifically to protect one
long-lived, already-crashed local MariaDB installation from schema-wildcard
`GRANT` statements. A GitHub Actions service container is created fresh for
every job run and destroyed immediately after — there is no persistent
installation to protect, and replicating three separate principals with
staged grants would add real complexity for no corresponding safety benefit
in an ephemeral environment. `DB_USERNAME` and `DB_MIGRATOR_USERNAME` both
resolve to `root` in CI.

**The CI database password is a plain, non-secret value committed directly
in the workflow file.** `ci_root_password` authenticates to nothing outside
the one ephemeral container it's scoped to, for the few minutes that
container exists. This is not a credential in the sense `AGENTS.md`'s "never
commit secrets... production credentials" instruction is protecting against
— there is no production system, real user, or persistent data behind it.
Treating it as a GitHub Actions secret would add indirection with no actual
security benefit.

**`.env`/`.env.testing` are generated fresh in a workflow step**, from the
already-committed `.env.example`/`.env.testing.example` templates, with only
the `DB_*` lines rewritten to point at the service container. No real `.env`
file exists in the repository or is ever touched by CI.

**Plain `npm ci`, not `npm ci --ignore-scripts`.** Local sessions used
`--ignore-scripts` specifically to avoid a Windows-only Rolldown native-
binding `EPERM` conflict with a locally-running dev server process. GitHub's
`ubuntu-latest` runners don't have that problem, and skipping install
scripts unconditionally risks silently missing a native build some
dependency needs.

**`pip-audit` runs via `pipx run`, isolated from the project's own
dependencies** — the same "isolated pip-audit 2.10.1" pattern already used
locally (see `PROGRESS.md`), so the audit tool's own dependency tree never
mixes with the ml-service's pinned versions.

**Branch protection (making these checks required before merge) is
out of scope for this change.** That is a GitHub repository *setting*, not a
code change — a separate, more consequential action that affects every
future push and pull request to this repository. It is left for the user to
enable through GitHub's UI once the workflow has actually run successfully.

## Consequences

- A workflow file cannot be fully verified by reading it — GitHub Actions
  only proves it correct by actually running it. The first real signal of
  whether every version pin (PHP 8.2, Node 24, Python 3.14, MariaDB 10.4) and
  every command still works comes from the first real run on GitHub, not
  from anything checked locally.
- If the local MariaDB's `grc_app`/`grc_migrator`/`grc_test` split ever
  changes, CI does not need to change with it — CI's single-root-user
  approach is deliberately decoupled from the local machine's principal
  design.
- Enabling required status checks is a one-time manual step still owed
  after this change lands.

#!/usr/bin/env node
// Resets the isolated grc_enrollment_test database once per E2E run — never
// per test. migrate:fresh issues only DDL against a database already
// dedicated to testing; it never touches grc_enrollment (dev) and never
// issues a schema-wildcard GRANT, the one statement shape documented to have
// crashed this MariaDB install (docs/runbooks/mariadb-local.md). --seed runs
// the full DatabaseSeeder chain: the 10 supported-role identities, 3 student lifecycle
// scenarios, and the reference catalog (programs, terms, subjects,
// curricula, sections) every journey's API-arranged preconditions build on.
//
import { spawnSync } from "node:child_process"
import { fileURLToPath } from "node:url"
import path from "node:path"

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const backendDir = path.resolve(__dirname, "..", "..", "backend")

const result = spawnSync(
  process.platform === "win32" ? "php.exe" : "php",
  ["artisan", "migrate:fresh", "--seed", "--env=testing", "--force"],
  { cwd: backendDir, stdio: "inherit" },
)

if (result.status !== 0) {
  console.error("e2e: database reset failed — see php artisan output above.")
  process.exit(result.status ?? 1)
}

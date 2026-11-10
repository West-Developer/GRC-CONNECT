# ADR 0013 — Next.js as the Presentation Layer

**Status:** Accepted
**Date:** 2026-07-28
**Supersedes:** the frontend-stack portion of PRD v3.1 §1.2, §6.1, §7, and §7.3

## Context

The April 2026 capstone manuscript's three-tier architecture diagram shows
**Next.js/React** in the Presentation Layer. PRD v3.1 deliberately overrode
this, recording in three separate places that a Vite-based React SPA would be
used instead — §7 stated it most directly: *"the current project instructions
deliberately replace Next.js with a Vite-based React + strict TypeScript SPA."*

That substitution is now reversed by project direction. Next.js becomes the
approved Presentation Layer, realigning the implementation with the
manuscript's own diagram.

PRD §18 requires the PRD be updated when approved architecture changes, so the
reversal cannot be made in code alone. This ADR is the decision record; PRD
v3.2 carries the normative text.

### What exists at the time of this decision

The current Vite frontend is thinner than its file count suggests:

- **4 real screens** — institutional landing, login, portal shell, 404.
- **40 role modules across the 9 portals are placeholder empty-states.** Every
  one renders *"This module is not connected to workflow or authorization
  APIs."* None fetches anything.
- **4 of 26 backend endpoints** are consumed by any UI.

Authentication is the one production-shaped part: real Sanctum bearer login,
`localStorage` token, `GET /auth/me` session restore, 401 auto-clear, strict
Zod contract parsing.

## Decision

### 1. Next.js (App Router) replaces Vite as the Presentation Layer

Routing moves to the file-system App Router; `react-router` is removed. No
separate routing library is used.

### 2. Migrate now, before the portals are built

40 modules are placeholders and 22 endpoints have no UI at all. Migrating now
costs 4 screens. Migrating after Phases 5–7 costs 44. This is the cheapest
moment the migration will ever have.

### 3. Next.js is used as a client-rendered application only

This is the load-bearing constraint of this ADR. Next.js brings server-side
capabilities this project deliberately does **not** adopt:

- No server-side session. PRD §9.1's bearer-token rule is unchanged.
- No server-side rendering of authorized student data.
- No API proxying or re-export through Next route handlers — ADR 0001's
  independently-runnable service boundary stands, and `frontend/` still must
  not be rendered by Laravel.
- Server components must not fetch authorized data.

Next.js is adopted for its routing, build pipeline, and ecosystem — not to
move computation to a Node server.

### 4. The bearer token stays in `localStorage`

Considered and rejected: moving the token to an httpOnly cookie to enable
Next.js middleware route protection.

Rejected because it contradicts PRD §9.1's explicit no-cookie,
no-`withCredentials` rule, requires backend CORS and credential changes, and
reopens CSRF concerns that bearer tokens currently avoid entirely.

**Accepted consequence:** Next.js middleware cannot read the token, so
server-side route protection is impossible. Route guards are client-side.
This is acceptable because it changes nothing about actual security — PRD §3
already states *"Hiding a control in the SPA is not sufficient
authorization,"* and Laravel Policies remain the sole authority on every
request. A client-side guard is a navigation convenience, not a security
boundary, and that was already true under Vite.

### 5. Demo authentication mode is deleted, not ported

The frontend carries a second, dev-only auth path: nine hardcoded users
sharing a committed password, gated to development and test builds by Vite's
`import.meta.env.MODE`.

It is deleted rather than migrated:

- It predates real authentication. Nine seeded database identities
  (`RoleUserSeeder`, `docs/testing/SEEDED_IDENTITIES.md`) now serve the same
  purpose against the real API.
- It is the migration's highest-risk item. Vite's `MODE === "test"` has no
  exact Next.js equivalent, and `NODE_ENV` does not distinguish test from
  development the same way. Porting the guard incorrectly would make a
  committed password a valid production login.

Removed: `demo-auth-gateway.ts`, `demo-users.ts`, `demo-session-store.ts`,
`demo-auth-mode.ts`, `demo-auth-types.ts`, their tests, and
`docs/testing/DEMO_CREDENTIALS.md`. `SEEDED_IDENTITIES.md` stays.

### 6. `src/app/` is reserved for routing; application code moves to `src/features/`

Today `src/app/` holds application code. Under Next.js, `app/` means the
router. Keeping both meanings in one directory would be a permanent source of
confusion, so PRD §7.3's path conventions are rewritten accordingly.

## Consequences

**Carries over unchanged:** the 1,930-line Tailwind v4 theme and GRC brand
tokens (`--grc-primary: #c8102e`), all 12 shadcn components, the strict-Zod API
client, the auth token module and service layer, TanStack Query, React Hook
Form, and every accessibility behavior.

**Must be rebuilt:** routing, the composition root, build and lint config, env
plumbing, and all 20 test files — `src/tests/render-app.tsx` wraps the router
in `MemoryRouter`, which the App Router has no equivalent for.

**Known migration hazards:**

- `main.tsx` mutates **module-level singletons** in `api-client.ts`
  (`let provideToken`). Module-scope mutable state is unsafe under Next.js and
  must move to provider scope.
- `public-api-readiness.tsx` uses
  `Intl.DateTimeFormat("en-PH", { timeZone: "Asia/Manila" })` — a hydration
  mismatch source.
- `components.json` sets `"rsc": false`, so no shadcn component currently
  declares `"use client"`.
- Backend `config/cors.php` allows only port 5173.

**CI must stay green** without weakening any gate: Node 24, Prettier
(`semi: false`), ESLint at `--max-warnings=0`, oxlint, `tsc`, Vitest, build,
and `npm audit --audit-level=moderate`. The `react-refresh` Vite ESLint preset
is removed.

**Deployment is unconstrained.** No frontend deployment procedure or workflow
exists yet — CI builds but never deploys, and XAMPP serves only MariaDB, never
the frontend. There is no production serving contract to preserve.

## Alternatives considered

**Stay on Vite.** Rejected by project direction. It would have kept the PRD
unchanged and freed the migration budget for portal work, but leaves the
implementation diverged from the manuscript's architecture diagram.

**Switch to Next.js without amending the PRD.** Rejected. PRD §18 requires the
document be updated when approved architecture changes, and `AGENTS.md` treats
`PRD.md` as the source of truth. A PRD that contradicts the code is worse than
either one alone.

**Adopt Next.js fully, including SSR and server-side auth.** Rejected. It would
require abandoning bearer tokens for cookies, contradicting PRD §9.1, and would
erode ADR 0001's service boundary by making the frontend a second server with
its own view of the data.

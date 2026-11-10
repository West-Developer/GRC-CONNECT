# GRC Enrollment Frontend

Independent React and strict-TypeScript SPA for the GRC Automated Enrollment
System. The current Phase 0 interface includes an institutional landing page,
public API readiness, real Sanctum bearer-token authentication, and
role-filtered portal previews for all nine PRD roles.

## Requirements

- Node.js 24
- npm 11
- the Laravel API running at the origin configured by `VITE_API_BASE_URL`,
  with the MariaDB identity foundation migrated and seeded (see
  `../docs/runbooks/mariadb-local.md`)

## Local Setup

```powershell
npm install
Copy-Item .env.example .env.local
npm run dev
```

The safe example points to `http://127.0.0.1:8000` and leaves `VITE_AUTH_MODE`
unset, which authenticates against the real API. Sign in with one of the
seeded identities in
[`../docs/testing/SEEDED_IDENTITIES.md`](../docs/testing/SEEDED_IDENTITIES.md)
— these are real database accounts, not client fixtures.

To work on the UI without a running backend, set `VITE_AUTH_MODE=demo` and use
the fixtures in
[`../docs/testing/DEMO_CREDENTIALS.md`](../docs/testing/DEMO_CREDENTIALS.md)
instead. Demo mode is accepted only in Vite development/test builds; a
production build always disables it even if the variable is set. The two
credential sets are deliberately distinct and never share a password.

## Auth Modes

| Mode            | Backend required | Credentials            | Where                            |
| --------------- | ---------------- | ---------------------- | -------------------------------- |
| `api` (default) | Yes              | `SEEDED_IDENTITIES.md` | Everywhere, including production |
| `demo`          | No               | `DEMO_CREDENTIALS.md`  | Development/test builds only     |
| `disabled`      | —                | none accepted          | Anywhere                         |

The bearer token lives in `localStorage`, owned exclusively by
`src/app/auth/auth-token.ts` — no other module reads, writes, or removes it.
This is intentionally separate from the demo session, which lives in
`sessionStorage` owned by `src/app/auth/demo-session-store.ts`. A 401 from any
authenticated request clears the token and returns the user to sign-in.

## Route Inventory

- `/` — institutional landing page with public readiness summary
- `/login` — sign-in (real API or demo fixtures, depending on `VITE_AUTH_MODE`)
- `/portal` — protected, role-filtered portal overview
- `/portal/:moduleId` — protected module preview resolved only from the
  signed-in role's catalog
- any unknown public route — branded not-found page

Anonymous protected-route requests are redirected to login with a validated
internal return path. Unknown or cross-role module IDs receive a scoped portal
not-found state. Client-side route filtering is a presentation boundary, not a
replacement for API authorization — every protected endpoint still requires a
valid bearer token server-side.

## Available Checks

```powershell
npm run format:check
npm run lint
npm run lint:fast
npm run typecheck
npm test
npm run build
npm audit --audit-level=moderate
```

## Current API Contract

```text
GET  /api/v1/health
POST /api/v1/auth/login
POST /api/v1/auth/logout   (Authorization: Bearer <token>)
GET  /api/v1/auth/me       (Authorization: Bearer <token>)
```

Every response is parsed with a strict Zod schema that rejects undeclared
fields. Example success envelope:

```json
{
  "data": {
    "type": "service-health",
    "service": "grc-enrollment-api",
    "status": "ok",
    "api_version": "v1",
    "generated_at": "RFC3339 UTC"
  }
}
```

Raw browser requests live only in `src/app/services/api-client.ts`. Rendering
components consume TanStack Query hooks and never call the API directly.

## Source Layout

```text
src/
  app/
    auth/          auth token store, demo/API gateways, session persistence, provider
    components/
      common/      public API readiness
      layouts/     public and role portal shells
      pages/       landing, login, portal, module, and not-found routes
      ui/          checked-in shadcn source components
    hooks/
    lib/
    portal/        single role capability and module catalog
    router/        routes, guards, and safe return-path parser
    schemas/
    services/
    types/
  tests/
  main.tsx
```

The shadcn/ui sources are checked into `src/app/components/ui`; the CLI is not
an application dependency. Newsreader and IBM Plex Sans are packaged locally
through Fontsource, so the interface makes no runtime font request.

## Deferred Work

Authorization policies beyond role-filtered navigation, business workflow
endpoints, password reset, and CI remain out of scope for this slice.
Institutional policy values from PRD §17 (including the approved token
expiration policy) remain unconfirmed and are not hardcoded — see
`backend/config/sanctum.php` for the provisional local default.

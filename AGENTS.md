# GRC Enrollment System — Repository Instructions

- Read `PRD.md` and all of `PROGRESS.md` before changing code. Treat `PRD.md` as the product and architecture source of truth.
- Follow the exact architecture and stack in `PRD.md`. Keep `frontend/`, `backend/`, and `ml-service/` independently runnable.
- Implement one coherent PRD phase or vertical slice at a time. Do not invent institutional rules or policy values.
- In Laravel, use versioned REST routes under `/api/v1`, Sanctum bearer tokens, Form Requests, Policies, Actions or Services, API Resources, database transactions, reversible migrations, and tests as required by the PRD.
- In React, use strict TypeScript, TanStack Query, React Hook Form, Zod, Tailwind CSS, and shadcn/ui. Keep API calls in service modules and never make them directly from rendering components.
- Preserve bearer-token authentication. Never introduce session-cookie or CSRF-cookie authentication.
- Protect records and analytics with least-privilege authorization. Treat predictive outputs as advisory and never as automatic enrollment denials.
- Keep payment confirmation, withdrawal side effects, queue tickets, seat reservations, and COM generation idempotent.
- Never commit secrets, `.env` files, tokens, production credentials, personal student data, or production datasets.
- Update `PROGRESS.md` at session start, before a substantial task or long command, after every meaningful milestone or failure, and before ending.
- Work directly on the `main` branch unless the user explicitly directs otherwise. When the user requests a saving point, create an intentional scoped commit and push it to GitHub (`origin/main`); do not use local-only worktrees as the saving point.
- Run the narrowest relevant checks after each change and the full applicable suite before marking a phase complete. Never record a check as passed unless it actually ran successfully.
- Do not modify unrelated files or dependencies without recording the reason in `PROGRESS.md`.
- Do not commit or push unless the user explicitly requests a GitHub saving point.

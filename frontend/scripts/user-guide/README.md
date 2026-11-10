# User Manual and Guide tools

The guide lives at `/user-guide` (public, no sign-in). Its text is typed content in
`src/features/lib/user-guide/content/`, its pictures are real screenshots in
`public/user-guide/`, and its PDFs are the page itself printed to `public/downloads/`.
These scripts regenerate the pictures and the PDFs. Run them after the interface or the
content changes.

| Script                     | What it does                                                                                                                                                                                                                            |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `capture.mjs [id ...]`     | Signs in as each role on a **scratch** copy of the system, photographs every page in `shots.config.mjs`, writes `public/user-guide/<id>.jpg` and the size, alt text and numbered markers into `src/features/lib/user-guide/shots.json`. |
| `build-pdf.mjs [role ...]` | Prints `/user-guide?role=all` and `/user-guide?role=<role>` with the print stylesheet into `public/downloads/`. Needs only the frontend, no API.                                                                                        |
| `explore.mjs`              | Throwaway helper: one raw screenshot of every sidebar page per role, to decide what deserves a figure.                                                                                                                                  |

The scripts use `playwright` (a dev dependency) and `sharp` (installed with Next.js; the
repository's `overrides` already pin it), so nothing new was added to `package.json`.

## Never photograph real data

The pictures are published on a public page. Capture only against a scratch database that
holds the project's synthetic seed data, never the development or production database, and
make sure no real person appears:

- `WorkbookFacultyProfileSeeder` imports real professors from
  `Subject And Prerequisuite/Professor_Department_List.md`. In the scratch database replace
  every faculty name and email with invented ones, and set
  `curriculum_subjects.reference_professor_name` to `NULL`, before capturing anything.
- Rename the demo role accounts (the seeders call them "Seed ...") to plain invented names.

## Recipe

1. Start a private MariaDB on its own port with a scratch database, then
   `php artisan migrate --force && php artisan db:seed --force` with the `DB_*` process
   environment variables pointing at it. Check `DB::connection()->getDatabaseName()` first.
2. Run the backend with the same variables: `php -S 127.0.0.1:8001` from `backend/public`
   with `vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` as the
   router (not `artisan serve`, which can silently fall back to the development database).
3. Mint one short-lived Sanctum token per role on the scratch database and write them to a
   JSON file `{ "student": "...", "dean": "..." }` outside the repository.
4. Serve the frontend (`npm run dev` on `http://localhost:3000` is enough). It talks to
   `NEXT_PUBLIC_API_BASE_URL`; the scripts re-point those calls at the scratch backend.
5. `GUIDE_TOKENS=<file> node scripts/user-guide/capture.mjs`, then
   `node scripts/user-guide/build-pdf.mjs`.

Environment variables: `GUIDE_TOKENS` (token file), `GUIDE_BASE` (frontend, default
`http://localhost:3000`), `GUIDE_API_ORIGIN` (the API origin the frontend is built for,
default `http://127.0.0.1:8000`), `GUIDE_API_TARGET` (the scratch backend, default
`http://127.0.0.1:8001`).

Some pictures need the system in a particular state (a term in enrollment, students waiting
for approval, tickets in the queue). Those states were produced by driving the real
interface on the scratch database, in this order: the Registrar Head creates the term, the
Dean and the Executive Director approve and publish the plan, the Registrar Head starts
enrollment, students submit, Registrar Staff approve, the Cashier issues tickets and
confirms payment, and the Program Head approves an overload.

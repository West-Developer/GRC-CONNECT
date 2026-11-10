# Version Compatibility Record

**Reviewed:** 2026-07-26  
**Scope:** Phase 0A contract-first runnable shells  
**Status:** Accepted for the initial local scaffold; production runtime upgrade required

Production-stable versions were checked against official release records and the workstation before generation. Lockfiles and installed manifests remain the exact source for transitive dependency versions.

## Selected Baseline

| Layer | Selected Phase 0A baseline | Local runtime | Decision |
|---|---|---|---|
| Backend | Laravel 12.64.x on PHP 8.2 | PHP 8.2.12, Composer 2.9.2 | Short-lived compatibility bridge. Update PHP to 8.2.32 immediately; move to PHP 8.4/8.5 and Laravel 13 before the production platform is finalized. |
| API authentication | Sanctum 4.3.x bearer personal access tokens | Deferred to Phase 1 | Compatible with Laravel 12/13 and PHP 8.2+. Do not enable stateful/cookie SPA authentication. |
| Frontend runtime | Node 24 LTS and npm 11 | Node 24.14.1, npm 11.11.0 | Compatible; update to the latest Node 24 patch before producing the final CI/deployment image. |
| Frontend application | React 19.2.x, TypeScript 6.0.x, Vite 8.1.x | Compatible | TypeScript 7 is deferred because current ecosystem tooling does not support its changed programmatic API. |
| Frontend styling | Tailwind CSS 4.3.x and shadcn CLI 4.15.x | Compatible | Browser support must be confirmed with GRC before Phase 0 completes. |
| Frontend state/forms | TanStack Query 5.101.x, React Hook Form 7.83.x, Zod 4.4.x | Compatible | Installed in the foundation; forms remain deferred until a complete vertical slice uses them. |
| Frontend tests | Vitest 4.1.x; Playwright 1.62.x | Compatible | E2E began in Phase 8c (`e2e/`, its own root-level npm package) — `@playwright/test` ^1.62.0, `@axe-core/playwright` ^4.10.2, against real Next.js + Laravel + MariaDB, not mocks. See ADR 0016. |
| Prediction runtime | Python 3.14 with FastAPI 0.140.x | Python 3.14.3 | Compatible; update to Python 3.14.6 before the production lock/image is finalized. |
| Prediction libraries | pandas 3.0.5, scikit-learn 1.9.0, XGBoost 3.3.0 | Compatible wheels available | Pinned now for reproducibility; models and training data remain out of scope for Phase 0A. |
| Database | MySQL 8.4 LTS (PRD-specified); MariaDB 10.4.32 accepted as the local development substitute per ADR 0007 | XAMPP MariaDB 10.4.32, active on `127.0.0.1:3306` | Deviation, accepted for local development only. A checksum-verified isolated MySQL 8.4.10 install was attempted and abandoned after four review rounds without ever executing the lifecycle scripts once; see ADR 0007. Collation is `utf8mb4_unicode_ci`, not `utf8mb4_0900_ai_ci` (MySQL-8-only). Must be revisited before production. |

## Support and Upgrade Notes

- Current Laravel 13 requires PHP 8.3 or newer. Laravel 12 supports PHP 8.2, but its remaining support window makes it a bridge rather than the intended production foundation.
- PHP 8.2.12 is missing later PHP 8.2 security fixes. No production-like deployment should use it.
- Sanctum personal access tokens are the PRD-mandated mechanism. Token expiry is an open institutional/security decision; Sanctum's default must not silently become GRC policy.
- Tailwind CSS 4 has a modern-browser floor. The supported-browser list in PRD §17 must be confirmed before the frontend platform is declared final.
- The prediction environment uses conventional GIL-enabled CPython. Free-threaded Python is not adopted without a separate compatibility and performance evaluation.
- MySQL 8.4 is the LTS line selected for the PRD's “MySQL 8 LTS” requirement. Fresh migrations, rollback, constraints, and integration tests are now verified against MariaDB 10.4.32 per ADR 0007, not yet against MySQL 8; re-verify against real MySQL 8 before production.

## Primary References

- [Laravel 13.22.0 release](https://github.com/laravel/framework/releases/tag/v13.22.0)
- [Laravel 13 PHP requirement](https://raw.githubusercontent.com/laravel/framework/v13.22.0/composer.json)
- [Laravel 12.64.0 release](https://github.com/laravel/framework/releases/tag/v12.64.0)
- [Laravel support policy](https://laravel.com/docs/12.x/releases)
- [PHP supported versions](https://www.php.net/supported-versions.php)
- [Laravel Sanctum 4.3.3 release](https://github.com/laravel/sanctum/releases/tag/v4.3.3)
- [Official Vite React TypeScript template](https://raw.githubusercontent.com/vitejs/vite/create-vite%409.1.1/packages/create-vite/template-react-ts/package.json)
- [Vite 8 announcement](https://vite.dev/blog/announcing-vite8)
- [TypeScript 7 tooling limitation](https://devblogs.microsoft.com/typescript/announcing-typescript-7-0/)
- [Tailwind CSS compatibility](https://tailwindcss.com/docs/compatibility)
- [shadcn Vite installation](https://ui.shadcn.com/docs/installation/vite)
- [TanStack Query installation](https://tanstack.com/query/latest/docs/framework/react/installation)
- [Vitest guide](https://vitest.dev/guide/)
- [Playwright requirements](https://playwright.dev/docs/intro)
- [Python 3.14.6 release](https://www.python.org/downloads/release/python-3146/)
- [FastAPI 0.140.0](https://pypi.org/project/fastapi/0.140.0/)
- [pandas 3.0.5](https://pypi.org/project/pandas/3.0.5/)
- [scikit-learn 1.9.0](https://pypi.org/project/scikit-learn/1.9.0/)
- [XGBoost 3.3.0](https://pypi.org/project/xgboost/3.3.0/)
- [MySQL 8.4 LTS selection guidance](https://dev.mysql.com/doc/refman/8.4/en/which-version.html)

## Revalidation Triggers

Recheck this record before:

- upgrading any major framework or runtime;
- creating the production CI/deployment image;
- enabling Sanctum authentication;
- installing MySQL and running the first migration;
- confirming GRC's supported-browser policy;
- implementing or training a prediction model.

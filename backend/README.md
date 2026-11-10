# GRC Enrollment API

Laravel REST API for the GRC Automated Enrollment System. Application endpoints are
versioned under `/api/v1`. The React SPA is a separate service and is never rendered
by this application.

## Local setup

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan serve
```

The Phase 0A liveness endpoint is available at `GET /api/v1/health`. It does not
query a database, so it remains usable while the required MySQL 8 LTS environment
is unavailable.

Do not run migrations against the bundled XAMPP MariaDB instance. It is not the
PRD-required database.

## Checks

```powershell
composer validate --strict
composer format:check
composer analyse
composer test
composer check-platform-reqs
composer audit --locked --no-interaction
```

The audit command requires Packagist network access and must not be reported as
passed when that service times out.

Bearer-token authentication with Laravel Sanctum is reserved for Phase 1. Do not
introduce session-cookie or CSRF-cookie authentication.

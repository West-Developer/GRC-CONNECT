# DEVELOPMENT OF AN AUTOMATED ENROLLMENT SYSTEM WITH PREDICTIVE ANALYTICS

**GRC Connect** is a web-based enrollment system for Global Reciprocal Colleges (GRC), serving students, faculty, and authorized administrative offices. It brings together digital advising, prerequisite checking, class scheduling, enrollment approvals, payment confirmation, and Digital Certificate of Matriculation (COM) generation. Predictive analytics support enrollment and section planning and help authorized staff review attrition risks; predictions remain advisory.

---

## Group Members & Roles

| Group member                 | Role                                                |
| ---------------------------- | --------------------------------------------------- |
| **Casuncad, Westlie R.**      | Group Leader / Full Stack Developer                  |
| **Boado, Mark Frederick B.**  | Developer / Documentation and System QA Lead          |
| **Baluyot, Danhil C.**        | Tester / Quality Assurance / System Documentation    |
| **Cardenas, Mharc Angelo S.** | Tester / Quality Assurance / System Documentation    |
| **Curtivo, Denmar E.**        | Tester / Quality Assurance / System Documentation    |

---

## Tech Stack

| Layer                       | Technologies                                                                                                                       |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **Frontend**                | Next.js 16, React 19, strict TypeScript, Tailwind CSS, shadcn/ui, TanStack Query, React Hook Form, Zod                             |
| **Backend**                 | Laravel 12 REST API, PHP 8.3+, Laravel Sanctum bearer tokens                                                                       |
| **Database**                | MySQL 8 LTS target; XAMPP MariaDB 10.4 supported for local development ([ADR 0007](docs/adr/0007-mariadb-development-database.md)) |
| **Prediction service**      | Python 3.14, FastAPI, scikit-learn, XGBoost                                                                                        |
| **Tools & version control** | Git, GitHub, VS Code, Composer, npm, PHPUnit, Vitest, Playwright                                                                   |

The frontend, backend, and prediction service run independently. The browser calls Laravel endpoints under `/api/v1`; only Laravel calls the private prediction service.

## Project Structure

```text
frontend/     Next.js application and student/staff portals
backend/      Laravel API, migrations, seeders, and backend tests
ml-service/   Private Python prediction API
e2e/          Playwright integration journeys
docs/         Architecture decisions, API contract, and runbooks
scripts/      Local development launcher
```

---

## System Requirements & Prerequisites

Install the following before setting up the project:

- **Git** to clone the repository.
- **Node.js 24.x** and **npm 11+**, as specified in `frontend/package.json`.
- **PHP 8.3+** with required extensions, including `bcmath`, `mbstring`, `ctype`, `curl`, `dom`, `fileinfo`, `pdo_mysql`, `tokenizer`, `xml`, and `zip`. Run `composer check-platform-reqs` to verify your environment.
- **Composer 2.x** for Laravel dependencies.
- **MySQL/MariaDB server**, or **XAMPP** for a local MariaDB instance. XAMPP's bundled PHP may need a separate upgrade to meet the PHP requirement.
- **Python 3.14.x** with `pip` and `venv`, matching `ml-service/pyproject.toml`.
- **VS Code** or another editor, and optionally **phpMyAdmin** or a database client.

Commands below use Windows PowerShell. On macOS/Linux, use `cp` instead of `Copy-Item` and `.venv/bin/python` instead of `.venv\Scripts\python.exe`.

---

## Installation & Setup Instructions

### 1. Clone the Repository

```powershell
git clone https://github.com/West-Developer/GRC-CONNECT.git
cd GRC-CONNECT
```

### 2. Set Up the Laravel Backend

From the repository root:

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
composer check-platform-reqs
```

Copy environment templates only during first-time setup; preserve your local configuration on subsequent runs. Configure the database below before running migrations or starting the API.

### 3. Set Up the Frontend

In a new terminal, from the repository root:

```powershell
cd frontend
npm ci
Copy-Item .env.example .env.local
```

`npm ci` installs the versions recorded in `package-lock.json`. Set this in `frontend/.env.local`:

```env
NEXT_PUBLIC_API_BASE_URL=http://127.0.0.1:8000
```

Use the API origin without an `/api/v1` suffix; service modules add the versioned route. Restart the frontend after environment changes.

### 4. Set Up the Prediction Service

In another terminal, from the repository root:

```powershell
cd ml-service
py -3.14 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.lock
```

The lock file contains pinned runtime and development dependencies. See [the prediction-service README](ml-service/README.md) for details.

---

## Database Setup & Configuration

### 1. Start the Database Server

Start **MySQL** in XAMPP, or start your standalone database server. Start **Apache** as well if you use XAMPP's phpMyAdmin at `http://localhost/phpmyadmin`. Laravel and Next.js run the application through their own development servers.

### 2. Create the Database and Local Users

Using a privileged database client, execute this SQL. Replace both password placeholders with different private local passwords first:

```sql
CREATE DATABASE grc_enrollment
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'grc_app'@'127.0.0.1'
  IDENTIFIED BY 'REPLACE_WITH_PRIVATE_APP_PASSWORD';
CREATE USER 'grc_migrator'@'127.0.0.1'
  IDENTIFIED BY 'REPLACE_WITH_PRIVATE_MIGRATOR_PASSWORD';

GRANT SELECT, INSERT, UPDATE, DELETE
  ON grc_enrollment.* TO 'grc_app'@'127.0.0.1';
GRANT ALL PRIVILEGES
  ON grc_enrollment.* TO 'grc_migrator'@'127.0.0.1';
```

The application uses a limited database identity; a separate migration identity creates and alters tables. For an existing database, check users and grants first. See [the local database runbook](docs/runbooks/mariadb-local.md) for troubleshooting and isolated test-database setup.

### 3. Configure Backend Environment Variables

Edit **`backend/.env`**, using the passwords you created:

```env
APP_ENV=local
APP_URL=http://127.0.0.1:8000
FRONTEND_APP_URL=http://127.0.0.1:3000

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=grc_enrollment
DB_USERNAME=grc_app
DB_PASSWORD=REPLACE_WITH_PRIVATE_APP_PASSWORD
DB_MIGRATOR_USERNAME=grc_migrator
DB_MIGRATOR_PASSWORD=REPLACE_WITH_PRIVATE_MIGRATOR_PASSWORD

CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000
PREDICTION_SERVICE_URL=http://127.0.0.1:8100
```

For MySQL, set `DB_CONNECTION=mysql`. The explicitly selected `mariadb_migrator` connection below uses the separate migration credentials against the same MySQL/MariaDB database. Keep local `.env` files private.

### 4. Create the Schema and Seed Local Demo Data

From `backend/`:

```powershell
php artisan config:clear
php artisan migrate --database=mariadb_migrator
php artisan db:seed
```

**Laravel migrations are the schema source; a SQL dump is not required.** Seeders provide local/testing identities and demo data. Private rosters and production backups are excluded from this source distribution; roster-dependent scenarios require separately authorized local fixtures. Never run demo seeders against production.

---

## Running the Application

After setup, open three terminals at the repository root.

**Terminal 1 — Private prediction service**

```powershell
cd ml-service
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8100
```

**Terminal 2 — Laravel API**

```powershell
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

**Terminal 3 — Next.js frontend**

```powershell
cd frontend
npm run dev
```

Open **[http://127.0.0.1:3000](http://127.0.0.1:3000)** in your browser.

| Service                   | Local address                              |
| ------------------------- | ------------------------------------------ |
| Frontend / login          | `http://127.0.0.1:3000/login`              |
| Backend health            | `http://127.0.0.1:8000/api/v1/health`      |
| Private prediction health | `http://127.0.0.1:8100/internal/v1/health` |

Alternatively, start the configured services together from the repository root on Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-local.ps1
```

The launcher requires dependencies and database setup to be complete. It reuses a healthy prediction service, reports occupied API/frontend ports, and writes logs under ignored `artifacts/local-dev/`.

---

## Default Login Credentials (Local Development Only)

These accounts are created by the local/testing seeders. Each uses the development password **`password`**.

| Account / role     | Email                           |
| ------------------ | ------------------------------- |
| Student            | `student.seed@grc.test`         |
| Admission Staff    | `admission.seed@grc.test`       |
| Faculty            | `faculty.seed@grc.test`         |
| Program Chair      | `chair.seed@grc.test`           |
| Dean               | `dean.seed@grc.test`            |
| Executive Director | `executive.seed@grc.test`       |
| Registrar Head     | `registrar-head.seed@grc.test`  |
| Registrar Staff    | `registrar-staff.seed@grc.test` |
| Accounting Staff   | `accounting.seed@grc.test`      |

Never reuse demo credentials in a deployed environment.

If login asks for an email code, the example backend uses `MAIL_MAILER=array`, which does not deliver messages to an inbox. Configure a local mail catcher or an authorized mail provider before testing email-code flows; [the email runbook](docs/runbooks/render-email.md) describes the deployed provider configuration.

---

## Development Checks

Run commands inside the indicated service directory. Backend integration tests require a separate test database configured from `backend/.env.testing.example`; follow [the database runbook](docs/runbooks/mariadb-local.md). Never point tests at production.

```powershell
# backend/
composer validate --strict
composer format:check
composer analyse
composer test

# frontend/
npm run lint
npm run typecheck
npm test
npm run build

# ml-service/
.\.venv\Scripts\python.exe -m ruff check .
.\.venv\Scripts\python.exe -m mypy app
.\.venv\Scripts\python.exe -m pytest
```

Integrated Playwright journeys are in `e2e/`; its database-reset command must only target an isolated test database.

## Project Documentation

- [Product Requirements Document](PRD.md) — product scope and architecture.
- [Repository Instructions](AGENTS.md) — contribution and delivery rules.
- [Progress Log](PROGRESS.md) — publication status and subsequent work.
- [API Contract](docs/api/openapi.yaml) — versioned REST endpoints.
- [Architecture Decisions](docs/adr/) — implementation decisions and approved deviations.

Do not commit secrets, environment files, personal student records, or production datasets. Preserve bearer-token authentication and API authorization, and keep predictive results advisory.

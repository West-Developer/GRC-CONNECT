# GRC Prediction Service

Private FastAPI service for versioned prediction contracts. It is reachable by the Laravel backend only; the browser must never call this service directly.

The service exposes a database-independent health contract at
`GET /internal/v1/health` and the aggregate-only demand-prediction contract at
`POST /internal/v1/section-demand/predict`. It never accepts student-level
records.

## Local setup

```powershell
py -3.14 -m venv .venv
.\.venv\Scripts\python.exe -m pip install --upgrade pip
.\.venv\Scripts\python.exe -m pip install -r requirements-dev.txt
```

For the exact Python package versions verified in the Phase 0A Windows
environment, install `requirements.lock` instead. The file does not include
artifact hashes, so installers may select different platform wheels. Regenerate
it only after changing a direct dependency and rerunning every check.

## Run

For integrated local development, run the repository launcher from the project
root after completing the setup above:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\start-local.ps1
```

It verifies this service's private health endpoint before starting the Laravel
API and Next.js frontend. It reuses a healthy service instead of starting a
duplicate and writes child-process logs under `artifacts/local-dev/`. Use
`-PredictionOnly` to restore only this service for an already-running API and
frontend stack.

For direct diagnostics, this service remains independently runnable:

```powershell
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8100
```

OpenAPI is available only on the private service at `/internal/openapi.json`; interactive documentation is at `/internal/docs`.

## Checks

```powershell
.\.venv\Scripts\python.exe -m ruff check .
.\.venv\Scripts\python.exe -m ruff format --check .
.\.venv\Scripts\python.exe -m mypy app
.\.venv\Scripts\python.exe -m pytest
.\.venv\Scripts\python.exe -m pip check
```

Do not use production records for local development or tests. Future model artifacts belong under `models/` and are ignored by Git.
